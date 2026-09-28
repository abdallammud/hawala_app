<?php

namespace App\Support;

/**
 * All money in this app is integer cents (BIGINT columns, `*_cents`).
 * This helper only formats/parses; it never does arithmetic on decimals.
 */
class Money
{
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $symbol = self::symbol($currency);
        $negative = $cents < 0;
        $abs = abs($cents);

        $formatted = number_format($abs / 100, 2);

        return ($negative ? '-' : '').$symbol.$formatted;
    }

    /**
     * Parse a user-entered amount string (e.g. "1,234.56") into integer cents.
     */
    public static function parseCents(string $input): int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $input) ?? '0';

        if ($clean === '' || $clean === '-') {
            return 0;
        }

        return (int) round(((float) $clean) * 100);
    }

    public static function symbol(string $currency): string
    {
        return match ($currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'SEK', 'NOK', 'DKK' => 'kr ',
            'CHF' => 'CHF ',
            default => $currency.' ',
        };
    }
}
