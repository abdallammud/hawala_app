<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Commission values are stored as integers (basis points for percent, cents
 * for flat) but typed and shown as plain numbers: "2.5" means 2.5%, "0.20"
 * means $0.20. This is the only place that converts between the two.
 */
class CommissionInput
{
    /** What the form value is converted to for storage, or null when no value applies. */
    public static function toStored(?string $type, mixed $input): ?int
    {
        if (! in_array($type, ['percent', 'flat'], true) || $input === null || trim((string) $input) === '') {
            return null;
        }

        return BigDecimal::of(trim((string) $input))
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    /** The plain number shown in the form for a stored value. */
    public static function toDisplay(?string $type, ?int $stored): string
    {
        if ($stored === null || ! in_array($type, ['percent', 'flat'], true)) {
            return '';
        }

        $text = sprintf('%d.%02d', intdiv($stored, 100), $stored % 100);

        return $type === 'percent' ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Human wording of a stored value: "2.5%" or "$0.20". Used by the audit log. */
    public static function describe(?string $type, int $stored): string
    {
        return match ($type) {
            'percent' => self::toDisplay('percent', $stored).'%',
            'flat' => Money::format($stored),
            default => (string) $stored,
        };
    }
}
