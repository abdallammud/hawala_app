<?php

namespace App\Services\Expenses;

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Exceptions\Balances\SelfApprovalException;
use App\Exceptions\Expenses\ExpenseStateException;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Models\CompanyAccount;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\User;
use App\Notifications\ExpenseDecided;
use App\Notifications\ExpenseSubmitted;
use App\Services\Ledger\LedgerService;
use App\Services\Rates\QuoteService;
use App\Services\Rates\RateService;
use App\Support\Money;
use App\Support\NumberSequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operating costs (payroll, rent, bills, …) paid out of a company account.
 *
 * Every expense starts Pending and touches no balance. A different user
 * approves it: that posts a balanced entry — debit Operating expenses, credit
 * the company account — so company balances and the Profit report stay true.
 * Rejecting posts nothing. Returning an approved expense to pending posts the
 * reverse entry. Ledger rows are never edited or deleted (money rule 5), so
 * deleting an expense leaves its journal entries behind, memo and all.
 */
class ExpenseService
{
    public const SEQUENCE = 'expense';

    public const NUMBER_PREFIX = 'EX';

    public function __construct(
        private LedgerService $ledger,
        private QuoteService $quotes,
        private RateService $rates,
    ) {}

    /**
     * Record a new expense. It is Pending: no money moves until someone else approves it.
     *
     * @param  array{currency?:string,amount_cents:int,expense_date:\DateTimeInterface|string,payee?:?string,reference?:?string,description?:?string}  $data
     */
    public function record(User $by, ExpenseType $type, CompanyAccount $account, array $data, string $idempotencyKey): Expense
    {
        // Money rule 10: a repeated token returns the existing expense.
        $existing = Expense::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        [$currency, $originalCents, $usdCents, $rate] = $this->convert($data);

        $expense = DB::transaction(fn () => Expense::create([
            'number' => NumberSequence::format(self::NUMBER_PREFIX, NumberSequence::next(self::SEQUENCE)),
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
            'status' => ExpenseStatus::Pending,
            'created_by' => $by->id,
            'idempotency_key' => $idempotencyKey,
        ]));

        User::permission('expenses.approve')
            ->where('id', '!=', $by->id)
            ->each(fn (User $approver) => $approver->notify(new ExpenseSubmitted($expense)));

        return $expense;
    }

    /**
     * Change a pending expense. Nothing is posted yet, so any field may change;
     * once it is approved or rejected it is locked.
     *
     * @param  array{expense_type_id:int,company_account_id:int,currency:string,amount_cents:int,expense_date:\DateTimeInterface|string,payee?:?string,reference?:?string,description?:?string}  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        [$currency, $originalCents, $usdCents, $rate] = $this->convert($data);

        return DB::transaction(function () use ($expense, $data, $currency, $originalCents, $usdCents, $rate) {
            $fresh = $this->lock($expense);

            if (! $fresh->isPending()) {
                throw new ExpenseStateException(__('Only a pending expense can be changed.'));
            }

            $fresh->update([
                'expense_type_id' => $data['expense_type_id'],
                'company_account_id' => $data['company_account_id'],
                'amount_cents' => $usdCents,
                'original_currency' => $currency,
                'original_amount_cents' => $originalCents,
                'rate' => $rate,
                'expense_date' => $data['expense_date'],
                'payee' => $data['payee'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            return $fresh;
        });
    }

    /**
     * Approve a pending expense and pay it out of its company account.
     *
     * @throws SelfApprovalException when $by recorded the expense
     * @throws ExpenseStateException when it is no longer pending
     * @throws InsufficientBalanceException when the company account cannot cover it
     */
    public function approve(Expense $expense, User $by, ?string $reason = null): Expense
    {
        $approved = DB::transaction(function () use ($expense, $by, $reason) {
            $fresh = $this->lockForDecision($expense, $by, ExpenseStatus::Pending);
            $fresh->loadMissing(['type', 'companyAccount', 'createdBy']);

            $companyLedger = $this->ledger->accountFor($fresh->companyAccount, LedgerAccountType::Company);

            $entry = $this->ledger->post(
                JournalType::Expense,
                [
                    [$this->ledger->system(LedgerAccountType::Expenses), $fresh->amount_cents],
                    [$companyLedger, -$fresh->amount_cents],
                ],
                $fresh,
                $fresh->createdBy,
                $by,
                $this->memo($fresh),
                // Checked under the ledger's own row lock, not beforehand.
                [$companyLedger->id],
            );

            $fresh->update([
                'status' => ExpenseStatus::Approved,
                'journal_entry_id' => $entry->id,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_reason' => $this->clean($reason),
            ]);

            $this->log($fresh, $by, 'approved', $reason);

            return $fresh;
        });

        $approved->createdBy->notify(new ExpenseDecided($approved));

        return $approved;
    }

    /** Reject a pending expense. Nothing was posted, so nothing is reversed. */
    public function reject(Expense $expense, User $by, ?string $reason = null): Expense
    {
        $rejected = DB::transaction(function () use ($expense, $by, $reason) {
            $fresh = $this->lockForDecision($expense, $by, ExpenseStatus::Pending);

            $fresh->update([
                'status' => ExpenseStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_reason' => $this->clean($reason),
            ]);

            $this->log($fresh, $by, 'rejected', $reason);

            return $fresh;
        });

        $rejected->createdBy->notify(new ExpenseDecided($rejected));

        return $rejected;
    }

    /**
     * Undo a decision. An approved expense is paid back to its account with a
     * reversing entry; a rejected one simply becomes pending again. Either
     * way it is editable and deletable again.
     */
    public function returnToPending(Expense $expense, User $by, ?string $reason = null): Expense
    {
        return DB::transaction(function () use ($expense, $by, $reason) {
            $fresh = $this->lock($expense);

            if ($fresh->isPending()) {
                throw new ExpenseStateException(__('This expense is already pending.'));
            }

            if ($fresh->isOwnedBy($by)) {
                throw new SelfApprovalException;
            }

            if ($fresh->isApproved()) {
                $fresh->loadMissing(['type', 'companyAccount']);

                $this->ledger->post(
                    JournalType::ExpenseVoid,
                    [
                        [$this->ledger->accountFor($fresh->companyAccount, LedgerAccountType::Company), $fresh->amount_cents],
                        [$this->ledger->system(LedgerAccountType::Expenses), -$fresh->amount_cents],
                    ],
                    $fresh,
                    $by,
                    null,
                    __('Returned to pending').': '.$this->memo($fresh),
                );
            }

            $fresh->update([
                'status' => ExpenseStatus::Pending,
                'journal_entry_id' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_reason' => null,
            ]);

            $this->log($fresh, $by, 'returned', $reason);

            return $fresh;
        });
    }

    /**
     * Hard-delete a pending or rejected expense. An approved one has paid out
     * money, so it must be returned to pending first.
     */
    public function delete(Expense $expense, User $by): void
    {
        $attachment = null;

        DB::transaction(function () use ($expense, &$attachment) {
            $fresh = $this->lock($expense);

            if ($fresh->isApproved()) {
                throw new ExpenseStateException(__('An approved expense must be returned to pending before it can be deleted.'));
            }

            $attachment = $fresh->attachment_path;

            // Spatie logs the deletion (who, and the last values incl. the number).
            $fresh->delete();
        });

        if ($attachment) {
            Storage::disk('local')->delete($attachment);
        }
    }

    /** Store the receipt scan or photo, replacing any earlier one. */
    public function attach(Expense $expense, UploadedFile $file): Expense
    {
        $old = $expense->attachment_path;

        $path = $file->storeAs(
            'expense-attachments',
            (string) Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
            'local',
        );

        $expense->update(['attachment_path' => $path, 'attachment_name' => $file->getClientOriginalName()]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $expense;
    }

    /** @return array{0:string,1:int,2:int,3:?string} currency, typed cents, USD cents, rate */
    private function convert(array $data): array
    {
        $currency = $data['currency'] ?? 'USD';
        $originalCents = (int) $data['amount_cents'];

        if ($originalCents <= 0) {
            throw new InvalidArgumentException('An expense must be more than zero.');
        }

        // Market rate, no margin — this is a cost, not an FX sale.
        $usdCents = $this->quotes->toUsd($currency, $originalCents);
        $rate = $currency === 'USD' ? null : (string) $this->rates->current($currency);

        return [$currency, $originalCents, $usdCents, $rate];
    }

    private function lock(Expense $expense): Expense
    {
        return Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
    }

    /** Lock the row, check it is in the expected state, and that the decider did not record it. */
    private function lockForDecision(Expense $expense, User $by, ExpenseStatus $expected): Expense
    {
        $fresh = $this->lock($expense);

        // Maker ≠ checker, checked on the locked row so it cannot be raced.
        if ($fresh->isOwnedBy($by)) {
            throw new SelfApprovalException;
        }

        if ($fresh->status !== $expected) {
            throw new ExpenseStateException(__('This expense has already been decided.'));
        }

        return $fresh;
    }

    private function log(Expense $expense, User $by, string $event, ?string $reason): void
    {
        $description = match ($event) {
            'approved' => __('Approved'),
            'rejected' => __('Rejected'),
            default => __('Returned to pending'),
        };

        activity('expense')
            ->performedOn($expense)
            ->causedBy($by)
            ->event($event)
            ->withProperties(['reason' => $this->clean($reason)])
            ->log($description);
    }

    private function clean(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    private function memo(Expense $expense): string
    {
        return trim($expense->number.' · '.$expense->type->name.' — '.($expense->payee ?: Money::format($expense->amount_cents)));
    }
}
