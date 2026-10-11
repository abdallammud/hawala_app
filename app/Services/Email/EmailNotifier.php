<?php

namespace App\Services\Email;

use App\Enums\EmailEvent;
use App\Jobs\SendEmailLog;
use App\Models\BalanceRequest;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Expense;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Decides who is emailed for each event, renders the text from the editable
 * template, writes one email_logs row per recipient and queues the sending.
 *
 * Callers invoke it after their transaction commits, and nothing here can break
 * the money operation that triggered it: a failure is reported and swallowed.
 * Nothing happens at all until email is switched on and has an account.
 */
class EmailNotifier
{
    public function __construct(private EmailTemplateRenderer $renderer) {}

    /** A new transfer is waiting: every active payout agent of the destination branch. */
    public function transferSent(Transfer $transfer): void
    {
        $this->safely(function () use ($transfer) {
            $recipients = User::query()
                ->permission('queue.claim')
                ->where('branch_id', $transfer->branch_id)
                ->whereIn('type', ['payout_agent', 'dual_agent'])
                ->when(! Settings::get('payout.allow_self_claim', false), fn ($q) => $q->where('users.id', '!=', $transfer->sending_agent_id))
                ->get();

            $this->queue(EmailEvent::TransferSent, $recipients, $this->transferValues($transfer, $transfer->created_at), route('queue.index'), ['transfer_id' => $transfer->id]);
        });
    }

    /** Paid: the sending agent gets the receipt. */
    public function transferPaid(Transfer $transfer): void
    {
        $this->safely(function () use ($transfer) {
            $this->queue(
                EmailEvent::TransferPaid,
                collect([$transfer->sendingAgent]),
                $this->transferValues($transfer, $transfer->paid_at),
                route('transfers.show', $transfer),
                ['transfer_id' => $transfer->id],
            );
        });
    }

    public function transferCancelled(Transfer $transfer): void
    {
        $this->safely(function () use ($transfer) {
            $this->queue(
                EmailEvent::TransferCancelled,
                collect([$transfer->sendingAgent]),
                $this->transferValues($transfer, $transfer->cancelled_at) + ['reason' => $transfer->cancel_reason],
                route('transfers.show', $transfer),
                ['transfer_id' => $transfer->id],
            );
        });
    }

    /** The agent's claim timed out and the transfer went back to the queue. */
    public function claimExpired(Transfer $transfer, ?User $agent): void
    {
        if (! $agent) {
            return;
        }

        $this->safely(function () use ($transfer, $agent) {
            $this->queue(EmailEvent::ClaimExpired, collect([$agent]), $this->transferValues($transfer, now()), route('queue.index'), ['transfer_id' => $transfer->id]);
        });
    }

    public function largeTransfer(Transfer $transfer): void
    {
        $this->safely(function () use ($transfer) {
            $recipients = User::permission('transfers.approve_large')->where('users.id', '!=', $transfer->sending_agent_id)->get();

            $this->queue(EmailEvent::LargeTransfer, $recipients, $this->transferValues($transfer, $transfer->created_at), route('transfers.show', $transfer), ['transfer_id' => $transfer->id]);
        });
    }

    public function topupApproved(BalanceRequest $request): void
    {
        $this->safely(function () use ($request) {
            $request->loadMissing(['user', 'decidedBy']);

            $this->queue(
                EmailEvent::TopupApproved,
                collect([$request->user]),
                [
                    'amount' => Money::format($request->amount_cents),
                    'kind' => $request->kind->label(),
                    'date' => $this->when($request->decided_at ?? now()),
                    'decided_by' => $request->decidedBy?->name,
                ],
                route('balance-requests.index'),
                ['balance_request_id' => $request->id],
            );
        });
    }

    /** An expense was added: the people who can approve it, never the person who added it. */
    public function expenseSubmitted(Expense $expense): void
    {
        $this->safely(function () use ($expense) {
            $recipients = User::permission('expenses.approve')->where('users.id', '!=', $expense->created_by)->get();

            $this->queue(EmailEvent::ExpenseSubmitted, $recipients, $this->expenseValues($expense), route('expenses.show', $expense), ['expense_id' => $expense->id]);
        });
    }

    /** Approved or rejected: the person who added it hears back, with the reason. */
    public function expenseDecided(Expense $expense): void
    {
        $this->safely(function () use ($expense) {
            $event = $expense->isApproved() ? EmailEvent::ExpenseApproved : EmailEvent::ExpenseRejected;

            $this->queue($event, collect([$expense->createdBy]), $this->expenseValues($expense), route('expenses.show', $expense), ['expense_id' => $expense->id]);
        });
    }

    /**
     * Render for each eligible recipient, log, and queue.
     *
     * @param  Collection<int, User|null>  $recipients
     * @param  array<string, scalar|null>  $values
     * @param  array<string, int>  $refs  transfer_id / expense_id / balance_request_id
     * @return Collection<int, EmailLog>
     */
    public function queue(EmailEvent $event, Collection $recipients, array $values, string $link, array $refs = []): Collection
    {
        if (! EmailSettings::ready()) {
            return collect();
        }

        $template = EmailTemplate::query()->where('event', $event)->first();

        if ($template && ! $template->is_active) {
            return collect();
        }

        $subject = $template?->subject ?? $event->defaultSubject();
        $body = $template?->body ?? $event->defaultBody();
        $values += ['company_name' => Settings::get('general.company_name', config('app.name')), 'link' => $link];

        return $this->eligible($recipients)->map(function (User $user) use ($event, $subject, $body, $values, $refs) {
            $personal = $values + ['recipient_name' => $user->name];

            $log = EmailLog::create([
                'event' => $event,
                'to_email' => $user->email,
                'to_name' => $user->name,
                'user_id' => $user->id,
                'subject' => $this->renderer->renderSubject($subject, $event, $personal),
                'body' => $this->renderer->render($body, $event, $personal),
                'status' => EmailLog::QUEUED,
                'attach_receipt' => $event->attachesReceipt(),
            ] + $refs);

            // With the sync queue (local development) a failed send throws here;
            // it must not stop the remaining recipients.
            try {
                SendEmailLog::dispatch($log->id);
            } catch (Throwable $e) {
                report($e);
            }

            return $log;
        });
    }

    /** Active, opted in, with an address that could be one. One email per person. */
    private function eligible(Collection $recipients): Collection
    {
        return $recipients
            ->filter(fn (?User $u) => $u && $u->is_active && $u->email_notifications && filter_var($u->email, FILTER_VALIDATE_EMAIL))
            ->unique('id')
            ->values();
    }

    private function transferValues(Transfer $transfer, $date): array
    {
        $transfer->loadMissing(['branch', 'sendingAgent', 'claimedBy', 'paidBy']);

        return [
            'code' => $transfer->code,
            'amount' => Money::format($transfer->amount_cents),
            'fee' => Money::format($transfer->fee_cents),
            'sender_name' => $transfer->sender_name,
            'receiver_name' => $transfer->receiver_name,
            'sender_phone' => $transfer->sender_phone,
            'receiver_phone' => $transfer->receiver_phone,
            'branch' => $transfer->branch?->name,
            'agent_name' => $transfer->sendingAgent?->name,
            'payout_agent_name' => $transfer->paidBy?->name ?? $transfer->claimedBy?->name,
            'date' => $this->when($date ?? $transfer->created_at),
            'status' => $transfer->status->label(),
        ];
    }

    private function expenseValues(Expense $expense): array
    {
        $expense->loadMissing(['type', 'companyAccount', 'createdBy', 'decidedBy']);

        return [
            'expense_number' => $expense->number,
            'amount' => Money::format($expense->amount_cents),
            'expense_type' => $expense->type->name,
            'payee' => $expense->payee,
            'account' => $expense->companyAccount->name,
            'date' => $expense->expense_date->format('Y-m-d'),
            'created_by' => $expense->createdBy->name,
            'decided_by' => $expense->decidedBy?->name,
            'reason' => $expense->decision_reason,
        ];
    }

    private function when($date): string
    {
        return $date ? $date->copy()->timezone(Settings::get('general.timezone', 'Africa/Mogadishu'))->format('Y-m-d H:i') : '';
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
