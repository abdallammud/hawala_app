<?php

namespace App\Services\Sms;

use App\Enums\SmsEvent;
use App\Enums\SmsStatus;
use App\Models\SmsLog;
use App\Support\Settings;
use Throwable;

/**
 * The only way an SMS leaves the system. Picks the configured driver,
 * records every attempt in sms_logs, and never lets a gateway failure break
 * the money operation that triggered it.
 */
class SmsSender
{
    /**
     * Send one message and log it.
     *
     * Returns the log row. A failed send is recorded, not thrown — SMS is a
     * courtesy, and a dead gateway must never roll back a paid transfer.
     */
    public function send(string $to, string $message, SmsEvent $event, ?int $transferId = null): SmsLog
    {
        $log = SmsLog::create([
            'to' => $to,
            'body' => $message,
            'status' => SmsStatus::Queued,
            'transfer_id' => $transferId,
            'event' => $event,
        ]);

        if (! Settings::get('sms.enabled', false)) {
            return tap($log)->update([
                'status' => SmsStatus::Failed,
                'provider_response' => 'SMS is switched off (sms.enabled)',
            ]);
        }

        try {
            $response = $this->driver()->send($to, $message);

            $log->update(['status' => SmsStatus::Sent, 'provider_response' => $response]);
        } catch (Throwable $e) {
            $log->update([
                'status' => SmsStatus::Failed,
                'provider_response' => (string) str($e->getMessage())->limit(500),
            ]);
        }

        return $log->refresh();
    }

    /**
     * Send without touching sms_logs or the enabled switch — used by the
     * "Send test SMS" button so an admin can prove the gateway works before
     * turning SMS on for real.
     *
     * @throws SmsDeliveryException
     */
    public function sendTest(string $to, string $message): string
    {
        return $this->driver()->send($to, $message);
    }

    public function driver(): SmsDriver
    {
        return match (Settings::get('sms.driver', 'log')) {
            'http' => app(HttpDriver::class),
            default => app(LogDriver::class),
        };
    }
}
