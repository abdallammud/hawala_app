<?php

namespace App\Enums;

enum UserType: string
{
    case Staff = 'staff';
    case SendingAgent = 'sending_agent';
    case PayoutAgent = 'payout_agent';
    case DualAgent = 'dual_agent';

    public function label(): string
    {
        return match ($this) {
            self::Staff => __('Staff'),
            self::SendingAgent => __('Sending agent'),
            self::PayoutAgent => __('Payout agent'),
            self::DualAgent => __('Sending & payout agent'),
        };
    }

    /** Sends transfers (and so holds a send balance and sending commission). */
    public function sends(): bool
    {
        return in_array($this, [self::SendingAgent, self::DualAgent], true);
    }

    /** Pays transfers out (and so holds a payout balance and payout accounts). */
    public function pays(): bool
    {
        return in_array($this, [self::PayoutAgent, self::DualAgent], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
