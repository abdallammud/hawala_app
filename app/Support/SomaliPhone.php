<?php

namespace App\Support;

/**
 * Normalizes a Somali mobile number to digits-only "252" + 9 digits.
 *
 * The Somali gateways accept "+252…", "0…" and bare local numbers without
 * an error and then never deliver them; only "252XXXXXXXXX" arrives. So every
 * number is folded into that shape before sending, and anything that cannot
 * be rebuilt with confidence returns null — a guess could text a stranger.
 */
class SomaliPhone
{
    /** Fills in the first 1–3 digits of a local number that lost them. */
    public const DEFAULT_LOCAL_PREFIX = '616';

    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        // Strip the country code, then a stray trunk "0" left right after it
        // ("2520616957070" → "0616957070" → "616957070").
        if (str_starts_with($digits, '252')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // 6–8 digits: the leading digits of the local number are missing.
        $needed = 9 - strlen($digits);

        if ($needed > 0 && $needed <= 3) {
            $digits = substr(self::DEFAULT_LOCAL_PREFIX, 0, $needed).$digits;
        }

        if (strlen($digits) !== 9 || ! ctype_digit($digits)) {
            return null;
        }

        return '252'.$digits;
    }
}
