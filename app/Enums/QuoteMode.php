<?php

namespace App\Enums;

/**
 * Which leg of the transfer the agent typed. The other leg is derived
 * (BUILD.md §7). Everything stored stays USD either way.
 */
enum QuoteMode: string
{
    /** The customer hands over X in their currency; the fee comes out of it. */
    case Pay = 'pay';

    /** The receiver must be paid exactly X USD; the collected amount is derived. */
    case Receive = 'receive';

    public function label(): string
    {
        return match ($this) {
            self::Pay => __('Customer pays'),
            self::Receive => __('Receiver gets'),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
