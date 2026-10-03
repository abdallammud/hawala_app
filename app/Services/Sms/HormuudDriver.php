<?php

namespace App\Services\Sms;

use App\Enums\SmsProvider;

/**
 * Hormuud SMS API.
 *
 *   POST {base}/token        form Username, Password, grant_type=password → access_token
 *   POST {base}/api/SendSMS  {"mobile","message","senderid"}              → ResponseCode
 *
 * SendSMS answers HTTP 200 even when it fails: the verdict is ResponseCode,
 * whose key casing differs between accounts, and the real reason is in
 * Data.Description ("Zero Balance!!") rather than ResponseMessage ("Failed.").
 */
class HormuudDriver extends TokenGatewayDriver
{
    public const RESPONSE_CODES = [
        '200' => 'Success',
        '201' => 'Authentication failed',
        '203' => 'Invalid sender ID',
        '204' => 'Zero balance (prepaid account)',
        '205' => 'Insufficient balance (prepaid account)',
        '206' => 'Allowed message parts exceeded',
        '207' => 'Wrong mobile number',
        '500' => 'Unknown error',
    ];

    public const MAX_SENDER_ID = 11;

    public static function provider(): SmsProvider
    {
        return SmsProvider::Hormuud;
    }

    protected function login(GatewayCredentials $c): string
    {
        $response = $this->attempt(fn () => $this->http()->asForm()->post($c->url('token'), [
            'Username' => $c->username,
            'Password' => $c->password,
            'grant_type' => 'password',
        ]));

        $data = $response->json();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            $reason = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? null) : null;

            throw new SmsDeliveryException(__('Failed to get access token: :reason', [
                'reason' => is_scalar($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status(),
            ]));
        }

        return $token;
    }

    protected function deliver(GatewayCredentials $c, string $token, string $to, string $message): string
    {
        $payload = ['mobile' => $to, 'message' => $message];
        $sender = mb_substr($c->senderId, 0, self::MAX_SENDER_ID);

        if ($sender !== '') {
            $payload['senderid'] = $sender;
        }

        $response = $this->attempt(fn () => $this->http()
            ->withToken($token)
            ->withBody(json_encode($payload), 'application/json; charset=utf-8')
            ->post($c->url('api/SendSMS')));

        $data = $response->json();
        $code = self::get($data, 'ResponseCode');
        $code = is_scalar($code) ? (string) $code : null;

        if ($response->status() === 200 && $code === '200') {
            return (string) str($response->body())->limit(500);
        }

        $description = self::get(self::get($data, 'Data'), 'Description');
        $responseMessage = self::get($data, 'ResponseMessage') ?? self::get($data, 'Message');

        $reason = match (true) {
            is_scalar($description) && trim((string) $description) !== '' => (string) $description,
            $code !== null && isset(self::RESPONSE_CODES[$code]) => self::RESPONSE_CODES[$code],
            is_scalar($responseMessage) && trim((string) $responseMessage) !== '' => (string) $responseMessage,
            default => 'HTTP '.$response->status().': '.str($response->body())->limit(200),
        };

        $reason = $code !== null ? "{$reason} (code {$code})" : $reason;

        throw $code === '201' ? new TokenRejectedException($reason) : new SmsDeliveryException($reason);
    }

    /** Case-insensitive key lookup — Hormuud's key casing varies by account. */
    private static function get(mixed $array, string $key): mixed
    {
        if (! is_array($array)) {
            return null;
        }

        foreach ($array as $k => $value) {
            if (strcasecmp((string) $k, $key) === 0) {
                return $value;
            }
        }

        return null;
    }
}
