<?php

namespace App\Services\Sms;

/**
 * A way of actually putting an SMS on the wire. Drivers never touch
 * sms_logs — SmsSender owns the log row so every driver is recorded the
 * same way.
 */
interface SmsDriver
{
    /**
     * Deliver one message. Returns a short provider response to record.
     *
     * @throws SmsDeliveryException when the provider rejected it
     */
    public function send(string $to, string $message): string;
}
