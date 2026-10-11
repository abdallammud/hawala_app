<?php

namespace App\Services\Email;

use App\Support\Settings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * The company's sending account, read from the `email.*` settings. The app
 * password is stored encrypted with the app key and decrypted only here.
 */
final class EmailSettings
{
    public const MAILER = 'hawala';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromName,
        public readonly string $replyTo,
    ) {}

    public static function current(): self
    {
        $s = (array) Settings::get('email', []);

        return new self(
            trim((string) ($s['host'] ?? '')) ?: 'smtp.gmail.com',
            (int) ($s['port'] ?? 587) ?: 587,
            trim((string) ($s['username'] ?? '')),
            self::decrypt((string) ($s['password'] ?? '')),
            trim((string) ($s['from_name'] ?? '')) ?: (string) Settings::get('general.company_name', config('app.name')),
            trim((string) ($s['reply_to'] ?? '')),
        );
    }

    /** Same account with some values replaced (blank overrides are ignored) — for testing unsaved form values. */
    public function with(?string $host = null, ?int $port = null, ?string $username = null, ?string $password = null): self
    {
        return new self(
            filled($host) ? trim($host) : $this->host,
            $port ?: $this->port,
            filled($username) ? trim($username) : $this->username,
            filled($password) ? $password : $this->password,
            $this->fromName,
            $this->replyTo,
        );
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('email.enabled', false);
    }

    public function configured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /** True when switched on and there is an account to send from. */
    public static function ready(): bool
    {
        return self::enabled() && self::current()->configured();
    }

    public static function passwordSaved(): bool
    {
        return self::current()->password !== '';
    }

    /**
     * Point the `hawala` mailer at this account and return it. Built at send
     * time, so a password changed on the settings page applies to the next
     * email without a restart, and a queue worker never holds a stale one.
     */
    public function mailer(): Mailer
    {
        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => $this->host,
                'port' => $this->port,
                'username' => $this->username,
                'password' => $this->password,
                'timeout' => 20,
            ],
            // Gmail sends as the logged-in account whatever the From says.
            'mail.from' => ['address' => $this->username, 'name' => $this->fromName],
        ]);

        Mail::purge(self::MAILER);

        return Mail::mailer(self::MAILER);
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

    /** A plain-words version of the usual Gmail failures, so nobody has to read SMTP codes. */
    public static function explain(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, '535'), str_contains($message, 'Username and Password not accepted') => __('Gmail refused the login. Check the address, and use an App Password (not the normal Gmail password). 2-Step Verification must be on for the account.')." ({$message})",
            str_contains($message, '534'), str_contains($message, '5.7.9') => __('Gmail needs an App Password for this account.')." ({$message})",
            str_contains($message, 'Connection could not be established'), str_contains($message, 'timed out') => __('Could not reach the mail server. Check the host and port, and that the hosting allows outgoing mail on that port.')." ({$message})",
            str_contains($message, '5.4.5'), str_contains($message, 'Daily user sending') => __('Gmail has stopped sending for today (about 500 emails a day). It starts again within 24 hours.')." ({$message})",
            default => $message,
        };
    }
}
