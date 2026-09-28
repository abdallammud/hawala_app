<?php

namespace App\Enums;

enum UserType: string
{
    case Staff = 'staff';
    case SendingAgent = 'sending_agent';
    case PayoutAgent = 'payout_agent';

    public function label(): string
    {
        return match ($this) {
            self::Staff => __('Staff'),
            self::SendingAgent => __('Sending agent'),
            self::PayoutAgent => __('Payout agent'),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
