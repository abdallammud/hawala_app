<?php

namespace App\Services\Sms;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generic HTTP gateway driver. No provider has been chosen yet (BUILD.md
 * §13), so everything is configuration: URL, method, headers and a parameter
 * map whose values may contain {to}, {message} and {sender_id}.
 *
 * Example `sms.http` setting:
 *
 *   {
 *     "url": "https://gateway.example.com/send",
 *     "method": "POST",
 *     "send_as": "form",                       // form | json | query
 *     "sender_id": "BLUESTAR",
 *     "headers": {"Authorization": "Bearer ..."},
 *     "params": {"to": "{to}", "text": "{message}", "from": "{sender_id}"},
 *     "success_contains": "OK"                 // optional extra check
 *   }
 */
class HttpDriver implements SmsDriver
{
    public function send(string $to, string $message): string
    {
        $config = (array) Settings::get('sms.http', []);

        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            throw new SmsDeliveryException(__('No SMS gateway URL is configured.'));
        }

        $replacements = [
            '{to}' => $to,
            '{message}' => $message,
            '{sender_id}' => (string) ($config['sender_id'] ?? ''),
        ];

        $method = strtolower((string) ($config['method'] ?? 'post'));
        $sendAs = strtolower((string) ($config['send_as'] ?? ($method === 'get' ? 'query' : 'form')));

        // Values are substituted, never concatenated into the URL, so the
        // HTTP client handles escaping.
        $params = collect((array) ($config['params'] ?? []))
            ->map(fn ($value) => strtr((string) $value, $replacements))
            ->all();

        $headers = collect((array) ($config['headers'] ?? []))
            ->map(fn ($value) => strtr((string) $value, $replacements))
            ->all();

        try {
            $request = Http::withHeaders($headers)->timeout(15);

            $request = match ($sendAs) {
                'json' => $request->asJson(),
                'query' => $request,
                default => $request->asForm(),
            };

            $response = $method === 'get'
                ? $request->get(strtr($url, $replacements), $params)
                : $request->send($method, strtr($url, $replacements), $sendAs === 'query'
                    ? ['query' => $params]
                    : [$sendAs === 'json' ? 'json' : 'form_params' => $params]);
        } catch (Throwable $e) {
            throw new SmsDeliveryException($e->getMessage(), previous: $e);
        }

        $body = trim((string) $response->body());

        if ($response->failed()) {
            throw new SmsDeliveryException(sprintf('HTTP %d: %s', $response->status(), str($body)->limit(300)));
        }

        // Some gateways answer 200 with a failure in the body.
        $expected = (string) ($config['success_contains'] ?? '');

        if ($expected !== '' && ! str_contains($body, $expected)) {
            throw new SmsDeliveryException(sprintf('Unexpected response: %s', str($body)->limit(300)));
        }

        return (string) str($body)->limit(500);
    }
}
