<?php

namespace App\Services\Sms;

use App\Enums\SmsProvider;
use App\Support\Settings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * One gateway's saved account, read from `sms.<provider>`. The password is
 * stored encrypted with the app key and only ever decrypted here.
 */
final class GatewayCredentials
{
    public function __construct(
        public readonly SmsProvider $provider,
        public readonly string $username,
        public readonly string $password,
        public readonly string $senderId,
        public readonly string $apiUrl,
    ) {}

    public static function forProvider(SmsProvider $provider): self
    {
        $stored = (array) Settings::get('sms.'.$provider->value, []);

        return new self(
            $provider,
            trim((string) ($stored['username'] ?? '')),
            self::decrypt((string) ($stored['password'] ?? '')),
            trim((string) ($stored['sender_id'] ?? '')),
            trim((string) ($stored['api_url'] ?? '')) ?: $provider->defaultApiUrl(),
        );
    }

    /** Same account with some values replaced (blank overrides are ignored). */
    public function with(?string $username = null, ?string $password = null, ?string $apiUrl = null): self
    {
        return new self(
            $this->provider,
            filled($username) ? trim($username) : $this->username,
            filled($password) ? $password : $this->password,
            $this->senderId,
            filled($apiUrl) ? trim($apiUrl) : $this->apiUrl,
        );
    }

    public function configured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    public function url(string $path): string
    {
        return rtrim($this->apiUrl, '/').'/'.ltrim($path, '/');
    }

    public static function encrypt(string $password): string
    {
        return $password === '' ? '' : Crypt::encryptString($password);
    }

    private static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY changed since it was saved: treat as not set up.
            return '';
        }
    }
}
