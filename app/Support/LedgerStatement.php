<?php

namespace App\Support;

use App\Enums\NormalSide;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Running-balance statement for one ledger account: opening balance, each
 * line with a description, running balance, closing balance — most recent
 * first. Computed in memory (fine at this company's data volume) so the
 * running balance is exact under pagination.
 */
class LedgerStatement
{
    public static function build(LedgerAccount $account, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        $page ??= (int) request('page', 1);

        $lines = JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->with('journalEntry')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = 0;

        $rows = $lines->map(function (JournalLine $line) use ($account, &$running) {
            $running += $account->normal_side === NormalSide::Debit ? $line->amount_cents : -$line->amount_cents;

            return (object) [
                'created_at' => $line->created_at,
                'type' => $line->journalEntry->type,
                'memo' => $line->journalEntry->memo,
                'amount_cents' => $account->normal_side === NormalSide::Debit ? $line->amount_cents : -$line->amount_cents,
                'running_balance_cents' => $running,
            ];
        })->reverse()->values();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
