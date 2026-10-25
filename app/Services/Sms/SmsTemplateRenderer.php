<?php

namespace App\Services\Sms;

use App\Models\Transfer;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Settings;

/**
 * Fills the placeholders from BUILD.md §13. Anything the context cannot
 * supply renders empty rather than leaving a raw {placeholder} in a message
 * a customer will read.
 */
class SmsTemplateRenderer
{
    public const PLACEHOLDERS = [
        '{code}', '{sender_name}', '{sender_phone}', '{receiver_name}', '{receiver_phone}',
        '{amount_usd}', '{fee_usd}', '{branch}', '{date}', '{paid_date}', '{agent_name}', '{company_name}',
    ];

    public function render(string $body, array $values = []): string
    {
        $map = array_merge($this->defaults(), $values);

        $replacements = [];

        foreach (self::PLACEHOLDERS as $placeholder) {
            $key = trim($placeholder, '{}');
            $replacements[$placeholder] = (string) ($map[$key] ?? '');
        }

        return trim(strtr($body, $replacements));
    }

    /** The placeholder values a transfer can supply. */
    public function forTransfer(Transfer $transfer, array $extra = []): array
    {
        $transfer->loadMissing(['branch', 'sendingAgent']);

        return array_merge([
            'code' => $transfer->code,
            'sender_name' => $transfer->sender_name,
            'sender_phone' => $transfer->sender_phone,
            'receiver_name' => $transfer->receiver_name,
            'receiver_phone' => $transfer->receiver_phone,
            'amount_usd' => Money::format($transfer->amount_cents),
            'fee_usd' => Money::format($transfer->fee_cents),
            'branch' => $transfer->branch?->name,
            'date' => $transfer->created_at?->timezone(Settings::get('general.timezone', 'Africa/Mogadishu'))->format('Y-m-d H:i'),
            'paid_date' => $transfer->paid_at?->timezone(Settings::get('general.timezone', 'Africa/Mogadishu'))->format('Y-m-d H:i'),
            'agent_name' => $transfer->sendingAgent?->name,
        ], $extra);
    }

    /** Sample values so an admin can preview a template before saving it. */
    public function sampleValues(): array
    {
        return [
            'code' => 'HW-AB12C',
            'sender_name' => 'Ali Hassan',
            'sender_phone' => '+46701234567',
            'receiver_name' => 'Amina Abdi',
            'receiver_phone' => '+252612345678',
            'amount_usd' => Money::format(25000),
            'fee_usd' => Money::format(1250),
            'branch' => 'Mogadishu',
            'date' => LocalTime::now()->format('Y-m-d H:i'),
            'paid_date' => LocalTime::now()->format('Y-m-d H:i'),
            'agent_name' => 'Ali Hassan',
        ];
    }

    private function defaults(): array
    {
        return [
            'company_name' => Settings::get('general.company_name', config('app.name')),
        ];
    }
}
