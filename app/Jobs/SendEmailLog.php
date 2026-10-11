<?php

namespace App\Jobs;

use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Services\Email\EmailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one logged email. One job per recipient, so a bad address never holds
 * up the others. Tried three times a few minutes apart (Gmail hiccups pass),
 * then the log says failed, with the reason, and an admin can retry it.
 */
class SendEmailLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $logId) {}

    /** @return int[] seconds to wait before attempt 2 and 3 */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $log = EmailLog::query()->find($this->logId);

        if (! $log || $log->status !== EmailLog::QUEUED) {
            return;
        }

        if (! EmailSettings::ready()) {
            $log->update(['status' => EmailLog::SKIPPED, 'error' => 'Email was switched off before this could be sent.']);

            return;
        }

        $log->increment('attempts');

        try {
            EmailSettings::current()->mailer()->to($log->to_email, $log->to_name)->send(new TemplatedMail($log));
        } catch (Throwable $e) {
            // Kept as queued while retries remain; failed() flips it at the end.
            $log->update(['error' => mb_substr(EmailSettings::explain($e), 0, 1000)]);

            throw $e;
        }

        $log->update(['status' => EmailLog::SENT, 'sent_at' => now(), 'error' => $log->error && str_starts_with($log->error, 'Receipt') ? $log->error : null]);
    }

    public function failed(Throwable $e): void
    {
        EmailLog::query()->whereKey($this->logId)->where('status', EmailLog::QUEUED)->update([
            'status' => EmailLog::FAILED,
            'error' => mb_substr(EmailSettings::explain($e), 0, 1000),
        ]);
    }
}
