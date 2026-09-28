<?php

namespace App\Console\Commands;

use App\Enums\NormalSide;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyLedger extends Command
{
    protected $signature = 'hawala:verify-ledger';

    protected $description = 'Verify ledger integrity: balanced entries, cached balances, guarded balances, transfer consistency.';

    public function handle(): int
    {
        $problems = [
            ...$this->checkEntriesBalance(),
            ...$this->checkCachedBalances(),
            ...$this->checkGuardedBalances(),
        ];

        if (Schema::hasTable('transfers')) {
            $problems = [
                ...$problems,
                ...$this->checkTransfersPayable(),
                ...$this->checkCommissionSplits(),
                ...$this->checkTotalDebit(),
            ];
        } else {
            $this->info('Skipping transfer consistency checks — transfers table does not exist yet (Phase 5).');
        }

        if (empty($problems)) {
            $this->info('Ledger OK — no problems found.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' ledger '.(count($problems) === 1 ? 'problem' : 'problems').' found:');

        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }

    /** Every journal entry's lines must sum to exactly zero. */
    private function checkEntriesBalance(): array
    {
        $unbalanced = JournalLine::query()
            ->select('journal_entry_id', DB::raw('SUM(amount_cents) as total'))
            ->groupBy('journal_entry_id')
            ->havingRaw('SUM(amount_cents) != 0')
            ->get();

        return $unbalanced->map(fn ($row) => "Journal entry #{$row->journal_entry_id} does not balance (sum = {$row->total}).")->all();
    }

    /** Cached balance_cents must equal the recomputed natural-sign sum of its lines. */
    private function checkCachedBalances(): array
    {
        $problems = [];

        LedgerAccount::query()->each(function (LedgerAccount $account) use (&$problems) {
            $sum = (int) JournalLine::where('ledger_account_id', $account->id)->sum('amount_cents');
            $expected = $account->normal_side === NormalSide::Debit ? $sum : -$sum;

            if ($expected !== (int) $account->balance_cents) {
                $problems[] = "Ledger account #{$account->id} ({$account->name}): cached balance {$account->balance_cents} != recomputed {$expected}.";
            }
        });

        return $problems;
    }

    /** Guarded balances (send, credit, commission, and payout unless allowed) must be >= 0. */
    private function checkGuardedBalances(): array
    {
        $problems = [];
        $allowNegativePayout = Settings::get('payout.allow_negative', false);

        LedgerAccount::query()->each(function (LedgerAccount $account) use (&$problems, $allowNegativePayout) {
            $guarded = $account->type->isAlwaysGuarded()
                || ($account->type->value === 'agent_payout' && ! $allowNegativePayout);

            if ($guarded && $account->balance_cents < 0) {
                $problems[] = "Ledger account #{$account->id} ({$account->name}) is negative: {$account->balance_cents}.";
            }
        });

        return $problems;
    }

    /** transfers_payable balance = sum of amount_cents of transfers awaiting_approval/queued/claimed. */
    private function checkTransfersPayable(): array
    {
        $payable = LedgerAccount::query()->where('type', 'transfers_payable')->whereNull('owner_id')->first();

        if (! $payable) {
            return [];
        }

        $expected = (int) DB::table('transfers')
            ->whereIn('status', ['awaiting_approval', 'queued', 'claimed'])
            ->sum('amount_cents');

        if ($expected !== (int) $payable->balance_cents) {
            return ["transfers_payable balance {$payable->balance_cents} != sum of open transfers {$expected}."];
        }

        return [];
    }

    /** For every paid transfer: sending_share + payout_share + company_share = fee. */
    private function checkCommissionSplits(): array
    {
        $rows = DB::table('transfers')
            ->where('status', 'paid')
            ->whereRaw('COALESCE(sending_share_cents, 0) + COALESCE(payout_share_cents, 0) + COALESCE(company_share_cents, 0) != fee_cents')
            ->pluck('code');

        return $rows->map(fn ($code) => "Transfer {$code}: commission shares do not sum to the fee.")->all();
    }

    /** For every transfer: total_debit = amount + fee + fx_income. */
    private function checkTotalDebit(): array
    {
        $rows = DB::table('transfers')
            ->whereRaw('total_debit_cents != amount_cents + fee_cents + fx_income_cents')
            ->pluck('code');

        return $rows->map(fn ($code) => "Transfer {$code}: total_debit_cents != amount_cents + fee_cents + fx_income_cents.")->all();
    }
}
