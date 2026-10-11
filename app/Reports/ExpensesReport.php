<?php

namespace App\Reports;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Expenses in a period, by status, category and branch, scoped to the user's
 * own / branch / all. Only approved expenses have been paid, so that is the
 * default; the status filter can also show pending, rejected or all.
 */
class ExpensesReport extends Report
{
    public const ALL = 'all';

    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('Expenses');
    }

    public function description(): string
    {
        return __('Expenses in the period by category, branch and status, with the account each came from.');
    }

    public function permission(): string
    {
        return 'reports.expenses';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch', 'expense_type', 'status'];
    }

    public function statusChoices(): array
    {
        return ExpenseStatus::options() + [self::ALL => __('All statuses')];
    }

    public function defaultStatus(): string
    {
        return ExpenseStatus::Approved->value;
    }

    public function columns(): array
    {
        return [
            'number' => __('No.'),
            'date' => __('Date'),
            'type' => __('Category'),
            'payee' => __('Paid to'),
            'reference' => __('Reference'),
            'branch' => __('Branch'),
            'account' => __('Paid from'),
            'status' => __('Status'),
            'amount' => __('Amount (USD)'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return $this->query($filters, $user)
            ->with(['type', 'branch', 'companyAccount'])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Expense $e) => [
                'number' => $e->number,
                'date' => $e->expense_date->format('Y-m-d'),
                'type' => $e->type->name,
                'payee' => $e->payee ?: '—',
                'reference' => $e->reference ?: '—',
                'branch' => $e->branch?->name ?? '—',
                'account' => $e->companyAccount->name,
                'status' => $e->status->label(),
                'status_key' => $e->status->value,
                'amount' => $e->amount_cents,
            ]);
    }

    /**
     * What was actually paid. When the list mixes statuses only the approved
     * ones count, and `summary()` shows the others separately; when it is all
     * one status, that status's total.
     */
    public function totals(Collection $rows): array
    {
        $counted = $this->mixesStatuses($rows) ? $rows->where('status_key', ExpenseStatus::Approved->value) : $rows;

        return ['amount' => (int) $counted->sum('amount')];
    }

    public function totalLabel(Collection $rows): string
    {
        return $this->mixesStatuses($rows) ? __('Total approved') : __('Total');
    }

    public function summary(Collection $rows): array
    {
        if (! $this->mixesStatuses($rows)) {
            return [];
        }

        return collect(ExpenseStatus::cases())
            ->map(fn (ExpenseStatus $s) => [$s->label(), (int) $rows->where('status_key', $s->value)->sum('amount')])
            ->all();
    }

    private function mixesStatuses(Collection $rows): bool
    {
        return $rows->pluck('status_key')->unique()->count() > 1;
    }

    /** Shared with the by-category summary so both agree on what counts. */
    public function query(ReportFilters $filters, User $user)
    {
        $status = $filters->status ?? $this->defaultStatus();

        return Expense::query()
            ->visibleTo($user)
            ->when(
                ExpenseStatus::tryFrom((string) $status) !== null,
                fn ($q) => $q->where('status', $status),
            )
            // Half-open on the day after, so a date stored with a time part still counts on the last day.
            ->where('expense_date', '>=', $filters->from->toDateString())
            ->where('expense_date', '<', $filters->to->addDay()->toDateString())
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->expenseTypeId, fn ($q) => $q->where('expense_type_id', $filters->expenseTypeId));
    }
}
