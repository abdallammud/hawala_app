<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gap-free running numbers (HW-0001, EX-0001, …).
 *
 * The counter row is locked for the rest of the caller's transaction, so two
 * requests can never be handed the same number, and a transaction that rolls
 * back hands its number back too. It therefore must be called inside
 * DB::transaction().
 */
class NumberSequence
{
    public static function next(string $name): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('NumberSequence::next() must run inside a DB transaction.');
        }

        DB::table('number_sequences')->insertOrIgnore([
            'name' => $name,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('number_sequences')->where('name', $name)->lockForUpdate()->first();
        $next = (int) $row->last_value + 1;

        DB::table('number_sequences')->where('name', $name)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }

    /** "HW" + 7 → "HW-0007". Four digits at least; longer numbers just grow. */
    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
