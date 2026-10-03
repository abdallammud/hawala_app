<?php

namespace App\Services\Sms;

use App\Enums\SmsProvider;
use App\Support\SomaliPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared shape of the Somali gateways: log in for a bearer token, then post
 * the message. The token is cached in memory for a few minutes so a template
 * going to three recipients — or a queue drain of many jobs — logs in once.
 */
abstract class TokenGatewayDriver implements SmsDriver
{
    private const TOKEN_TTL_SECONDS = 300;

    /** @var array<string, array{0: string, 1: int}> key => [token, expires at] */
    private static array $tokens = [];

    public function __construct(private ?GatewayCredentials $credentials = null) {}

    abstract public static function provider(): SmsProvider;

    /**
     * @throws SmsDeliveryException with a reason an admin can act on
     */
    abstract protected function login(GatewayCredentials $c): string;

    /**
     * @return string short provider response to record
     *
     * @throws SmsDeliveryException
     */
    abstract protected function deliver(GatewayCredentials $c, string $token, string $to, string $message): string;

    public function send(string $to, string $message): string
    {
        $c = $this->credentials();

        $number = SomaliPhone::normalize($to)
            ?? throw new SmsDeliveryException(__('":to" is not a valid Somali mobile number.', ['to' => $to]));

        try {
            return $this->deliver($c, $this->token($c), $number, $message);
        } catch (TokenRejectedException) {
            // A cached token went stale: log in again once, then give up.
            return $this->deliver($c, $this->token($c, fresh: true), $number, $message);
        }
    }

    /**
     * Login only — proves the credentials without sending (or paying for) a message.
     *
     * @throws SmsDeliveryException
     */
    public function testConnection(): void
    {
        $this->login($this->credentials());
    }

    public static function flushTokens(): void
    {
        self::$tokens = [];
    }

    protected function http(): PendingRequest
    {
        // No redirects: the URL is admin-editable and we post credentials to it.
        return Http::timeout(20)->connectTimeout(10)->withoutRedirecting();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new SmsDeliveryException(__('Could not reach :provider: :error', [
                'provider' => static::provider()->label(),
                'error' => $e->getMessage(),
            ]), previous: $e);
        }
    }

    private function credentials(): GatewayCredentials
    {
        $c = $this->credentials ?? GatewayCredentials::forProvider(static::provider());

        if (! $c->configured()) {
            throw new SmsDeliveryException(__(':provider is not set up yet. Add its username and password in Settings → SMS.', [
                'provider' => static::provider()->label(),
            ]));
        }

        return $c;
    }

    private function token(GatewayCredentials $c, bool $fresh = false): string
    {
        $key = implode('|', [static::provider()->value, $c->apiUrl, $c->username, sha1($c->password)]);

        [$token, $expires] = self::$tokens[$key] ?? [null, 0];

        if (! $fresh && $token !== null && $expires > time()) {
            return $token;
        }

        $token = $this->login($c);
        self::$tokens[$key] = [$token, time() + self::TOKEN_TTL_SECONDS];

        return $token;
    }
}
