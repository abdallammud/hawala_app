<?php

namespace App\Enums;

enum CommissionType: string
{
    case Percent = 'percent';
    case Flat = 'flat';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percent of fee'),
            self::Flat => __('Flat amount'),
            self::None => __('None'),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
