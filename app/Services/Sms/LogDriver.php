<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log file instead of sending it. The sms_logs
 * row is written by SmsSender either way, so the whole pipeline can be
 * exercised before a provider is chosen.
 */
class LogDriver implements SmsDriver
{
    public function send(string $to, string $message): string
    {
        Log::channel(config('logging.default'))->info('SMS', ['to' => $to, 'message' => $message]);

        return 'logged';
    }
}
