<?php

namespace App\Services\Ledger;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\NormalSide;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Ledger\UnbalancedEntryException;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only class allowed to write journal entries or change
 * ledger_accounts.balance_cents — CLAUDE.md money rule 3.
 */
class LedgerService
{
    /**
     * Post a balanced journal entry.
     *
     * @param  array<int, array{0: LedgerAccount, 1: int}>  $lines  [LedgerAccount, signedCents] pairs.
     *                                                              Positive = debit, negative = credit.
     *                                                              Omit zero-amount lines — don't pass them.
     * @param  array<int, int>  $nonNegativeAccountIds  accounts that must not end below zero for this entry
     *                                                  even though their type is not guarded in general
     *                                                  (e.g. the company account an expense is paid from).
     */
    public function post(
        JournalType $type,
        array $lines,
        ?Model $reference,
        User $by,
        ?User $approvedBy = null,
        ?string $memo = null,
        array $nonNegativeAccountIds = [],
    ): JournalEntry {
        if (count($lines) < 2) {
            throw new UnbalancedEntryException('A journal entry needs at least two lines.');
        }

        $deltasByAccountId = [];

        foreach ($lines as [$account, $amountCents]) {
            if ($amountCents === 0) {
                throw new UnbalancedEntryException('Journal lines cannot be zero — omit them instead of passing zero.');
            }

            $deltasByAccountId[$account->id] = ($deltasByAccountId[$account->id] ?? 0) + $amountCents;
        }

        if (array_sum($deltasByAccountId) !== 0) {
            throw new UnbalancedEntryException('Journal entry lines must sum to exactly zero.');
        }

        return DB::transaction(function () use ($type, $deltasByAccountId, $reference, $by, $approvedBy, $memo, $nonNegativeAccountIds) {
            $accountIds = array_keys($deltasByAccountId);
            sort($accountIds);

            $accounts = LedgerAccount::query()
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $newBalances = [];

            foreach ($deltasByAccountId as $accountId => $delta) {
                $account = $accounts[$accountId];

                $newBalance = $account->normal_side === NormalSide::Debit
                    ? $account->balance_cents + $delta
                    : $account->balance_cents - $delta;

                if ($newBalance < 0 && ($this->isGuarded($account) || in_array($accountId, $nonNegativeAccountIds, true))) {
                    throw InsufficientBalanceException::forAccount($account, $newBalance);
                }

                $newBalances[$accountId] = $newBalance;
            }

            $entry = JournalEntry::create([
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'created_by' => $by->id,
                'approved_by' => $approvedBy?->id,
                'memo' => $memo,
                'created_at' => now(),
            ]);

            foreach ($deltasByAccountId as $accountId => $delta) {
                $entry->lines()->create([
                    'ledger_account_id' => $accountId,
                    'amount_cents' => $delta,
                    'created_at' => now(),
                ]);
            }

            foreach ($newBalances as $accountId => $balance) {
                $accounts[$accountId]->update(['balance_cents' => $balance]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Find or lazily create the ledger account for an owner (User or
     * CompanyAccount) + type.
     */
    public function accountFor(User|CompanyAccount $owner, LedgerAccountType $type): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            [
                'type' => $type->value,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ],
            [
                'normal_side' => $type->normalSide()->value,
                'name' => $this->accountName($owner, $type),
                'balance_cents' => 0,
            ]
        );
    }

    /**
     * Find or lazily create a system (ownerless) ledger account:
     * transfers_payable, fee_income, fx_income, commission_expense,
     * bank_charges, adjustments.
     */
    public function system(LedgerAccountType $type): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            [
                'type' => $type->value,
                'owner_type' => null,
                'owner_id' => null,
            ],
            [
                'normal_side' => $type->normalSide()->value,
                'name' => $type->label(),
                'balance_cents' => 0,
            ]
        );
    }

    private function isGuarded(LedgerAccount $account): bool
    {
        if ($account->type->isAlwaysGuarded()) {
            return true;
        }

        if ($account->type === LedgerAccountType::AgentPayout) {
            return ! Settings::get('payout.allow_negative', false);
        }

        return false;
    }

    private function accountName(User|CompanyAccount $owner, LedgerAccountType $type): string
    {
        return "{$owner->name} — {$type->label()}";
    }
}
