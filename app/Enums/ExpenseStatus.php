<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Posted => __('Paid'),
            self::Voided => __('Voided'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Posted => 'green',
            self::Voided => 'slate',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
