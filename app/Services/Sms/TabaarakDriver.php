<?php

namespace App\Services\Sms;

use App\Enums\SmsProvider;

/**
 * Tabaarak SMS Gateway.
 *
 *   POST {base}/Auth/SMSLogin  {"Name","Password"}           → data.token
 *   POST {base}/Sms/sendsms    {"smsMessage","mobile":[...]} → success: true
 *
 * There is no sender-ID field: Tabaarak takes the sender from the account.
 */
class TabaarakDriver extends TokenGatewayDriver
{
    public static function provider(): SmsProvider
    {
        return SmsProvider::Tabaarak;
    }

    protected function login(GatewayCredentials $c): string
    {
        $response = $this->attempt(fn () => $this->http()->asJson()->post($c->url('Auth/SMSLogin'), [
            'Name' => $c->username,
            'Password' => $c->password,
        ]));

        $token = $response->status() === 200 ? $response->json('data.token') : null;

        if (! is_string($token) || $token === '') {
            throw new SmsDeliveryException(__('Failed to get access token: :reason', [
                'reason' => $this->reason($response->json(), 'HTTP '.$response->status()),
            ]));
        }

        return $token;
    }

    protected function deliver(GatewayCredentials $c, string $token, string $to, string $message): string
    {
        $response = $this->attempt(fn () => $this->http()->asJson()->withToken($token)->post($c->url('Sms/sendsms'), [
            'smsMessage' => $message,
            'mobile' => [$to],
        ]));

        $data = $response->json();

        if ($response->status() === 401) {
            throw new TokenRejectedException($this->reason($data, 'HTTP 401'));
        }

        // Both checks: HTTP 200 alone does not mean it was accepted.
        if ($response->status() !== 200 || ($data['success'] ?? false) !== true) {
            throw new SmsDeliveryException($this->reason($data, 'HTTP '.$response->status().': '.str($response->body())->limit(200)));
        }

        return (string) str($response->body())->limit(500);
    }

    private function reason(mixed $data, string $fallback): string
    {
        $message = is_array($data) ? ($data['message'] ?? null) : null;

        return is_scalar($message) && trim((string) $message) !== '' ? (string) $message : $fallback;
    }
}
