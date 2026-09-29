<?php

namespace App\Enums;

enum TransferStatus: string
{
    case AwaitingApproval = 'awaiting_approval';
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingApproval => __('Awaiting approval'),
            self::Queued => __('Queued'),
            self::Claimed => __('Claimed'),
            self::Paid => __('Paid'),
            self::Cancelled => __('Cancelled'),
            self::Rejected => __('Rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingApproval => 'amber',
            self::Queued => 'blue',
            self::Claimed => 'gold',
            self::Paid => 'green',
            self::Cancelled, self::Rejected => 'red',
        };
    }

    /** Statuses whose amount still sits in transfers_payable (BUILD.md §4, check 4). */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingApproval, self::Queued, self::Claimed], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
