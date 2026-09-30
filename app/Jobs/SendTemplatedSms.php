<?php

namespace App\Jobs;

use App\Enums\SmsEvent;
use App\Models\SmsTemplate;
use App\Models\Transfer;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplateRenderer;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders the active template for an event and sends it to whichever
 * recipients that template has switched on.
 *
 * Queued so a slow gateway never holds up a counter transaction. Failures
 * are recorded in sms_logs by SmsSender rather than retried forever.
 */
class SendTemplatedSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public SmsEvent $event,
        public ?int $transferId = null,
        public array $extraValues = [],
        /** Phone numbers to use instead of resolving them from the transfer. */
        public array $overrideRecipients = [],
    ) {}

    public function handle(SmsSender $sender, SmsTemplateRenderer $renderer): void
    {
        if (! Settings::get('sms.enabled', false)) {
            return;
        }

        $template = SmsTemplate::query()
            ->where('event', $this->event)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return;
        }

        $transfer = $this->transferId
            ? Transfer::with(['branch', 'sendingAgent', 'claimedBy', 'paidBy'])->find($this->transferId)
            : null;

        $values = $transfer
            ? $renderer->forTransfer($transfer, $this->extraValues)
            : $this->extraValues;

        $message = $renderer->render($template->body, $values);

        if ($message === '') {
            return;
        }

        foreach ($this->recipients($template, $transfer) as $to) {
            $sender->send($to, $message, $this->event, $transfer?->id);
        }
    }

    /** @return string[] distinct, non-empty phone numbers */
    private function recipients(SmsTemplate $template, ?Transfer $transfer): array
    {
        if ($this->overrideRecipients !== []) {
            return array_values(array_unique(array_filter($this->overrideRecipients)));
        }

        if (! $transfer) {
            return [];
        }

        $numbers = [];

        if ($template->to_sender) {
            $numbers[] = $transfer->sender_phone;
        }

        if ($template->to_receiver) {
            $numbers[] = $transfer->receiver_phone;
        }

        if ($template->to_sending_agent) {
            $numbers[] = $transfer->sendingAgent?->phone;
        }

        if ($template->to_payout_agent) {
            // Whoever actually handled it: the payer if paid, else the claimer.
            $numbers[] = $transfer->paidBy?->phone ?? $transfer->claimedBy?->phone;
        }

        return array_values(array_unique(array_filter($numbers)));
    }
}
