<?php

namespace App\Enums;

/**
 * The SMS gateways the system can send through. Each keeps its own saved
 * account under `sms.<value>`, so switching provider is one setting and no
 * retyping.
 */
enum SmsProvider: string
{
    case Tabaarak = 'tabaarak';
    case Hormuud = 'hormuud';

    public function label(): string
    {
        return match ($this) {
            self::Tabaarak => __('Tabaarak SMS Gateway'),
            self::Hormuud => __('Hormuud SMS API'),
        };
    }

    public function defaultApiUrl(): string
    {
        return match ($this) {
            self::Tabaarak => 'https://sms.tabaarak.com',
            self::Hormuud => 'https://smsapi.hormuud.com',
        };
    }

    /** Hormuud puts the sender ID on every message; Tabaarak takes it from the account. */
    public function requiresSenderId(): bool
    {
        return $this === self::Hormuud;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}
