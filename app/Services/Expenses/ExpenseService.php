<?php

namespace App\Services\Expenses;

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Models\CompanyAccount;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Rates\QuoteService;
use App\Services\Rates\RateService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Operating costs (payroll, rent, bills, …) paid out of a company account.
 * Each one is a balanced ledger entry — debit Operating expenses, credit the
 * company account — so company balances and the Profit report stay true.
 * Ledger rows are never edited: voiding an expense posts the reverse entry.
 */
class ExpenseService
{
    public function __construct(
        private LedgerService $ledger,
        private QuoteService $quotes,
        private RateService $rates,
    ) {}

    /**
     * @param  array{currency?:string,amount_cents:int,expense_date:\DateTimeInterface|string,payee?:?string,reference?:?string,description?:?string}  $data
     *
     * @throws InsufficientBalanceException when the company account cannot cover it
     */
    public function record(User $by, ExpenseType $type, CompanyAccount $account, array $data, string $idempotencyKey): Expense
    {
        // Money rule 10: a repeated token returns the existing expense.
        $existing = Expense::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $currency = $data['currency'] ?? 'USD';
        $originalCents = (int) $data['amount_cents'];

        if ($originalCents <= 0) {
            throw new InvalidArgumentException('An expense must be more than zero.');
        }

        // Market rate, no margin — this is a cost, not an FX sale.
        $usdCents = $this->quotes->toUsd($currency, $originalCents);
        $rate = $currency === 'USD' ? null : (string) $this->rates->current($currency);

        return DB::transaction(function () use ($by, $type, $account, $data, $idempotencyKey, $currency, $originalCents, $usdCents, $rate) {
            $expense = Expense::create([
                'expense_type_id' => $type->id,
                'branch_id' => $by->branch_id,
                'company_account_id' => $account->id,
                'amount_cents' => $usdCents,
                'original_currency' => $currency,
                'original_amount_cents' => $originalCents,
                'rate' => $rate,
                'expense_date' => $data['expense_date'],
                'payee' => $data['payee'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ExpenseStatus::Posted,
                'created_by' => $by->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $companyLedger = $this->ledger->accountFor($account, LedgerAccountType::Company);

            $entry = $this->ledger->post(
                JournalType::Expense,
                [
                    [$this->ledger->system(LedgerAccountType::Expenses), $usdCents],
                    [$companyLedger, -$usdCents],
                ],
                $expense,
                $by,
                null,
                $this->memo($type, $expense),
                // Checked under the ledger's own row lock, not beforehand.
                [$companyLedger->id],
            );

            $expense->update(['journal_entry_id' => $entry->id]);

            return $expense;
        });
    }

    /** Descriptive fields only — the amount and account are in the ledger and cannot change. */
    public function updateDetails(Expense $expense, array $data): Expense
    {
        if ($expense->isVoided()) {
            throw new RuntimeException(__('A voided expense cannot be changed.'));
        }

        $expense->update(collect($data)->only(['expense_type_id', 'expense_date', 'payee', 'reference', 'description'])->all());

        return $expense;
    }

    /** Reverse a posted expense: the money goes back to the company account. */
    public function void(Expense $expense, User $by, string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $by, $reason) {
            $fresh = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isVoided()) {
                throw new RuntimeException(__('This expense has already been voided.'));
            }

            $entry = $this->ledger->post(
                JournalType::ExpenseVoid,
                [
                    [$this->ledger->accountFor($fresh->companyAccount, LedgerAccountType::Company), $fresh->amount_cents],
                    [$this->ledger->system(LedgerAccountType::Expenses), -$fresh->amount_cents],
                ],
                $fresh,
                $by,
                null,
                $reason,
            );

            $fresh->update([
                'status' => ExpenseStatus::Voided,
                'void_journal_entry_id' => $entry->id,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $fresh;
        });
    }

    private function memo(ExpenseType $type, Expense $expense): string
    {
        return trim($type->name.' — '.($expense->payee ?: Money::format($expense->amount_cents)));
    }
}
