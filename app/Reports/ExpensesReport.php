<?php

namespace App\Reports;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every expense paid in the period (voided ones left out), scoped to the
 * user's own / branch / all.
 */
class ExpensesReport extends Report
{
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
        return __('Every expense paid in the period, with the account it came from.');
    }

    public function permission(): string
    {
        return 'reports.expenses';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch', 'expense_type'];
    }

    public function columns(): array
    {
        return [
            'date' => __('Date'),
            'type' => __('Type'),
            'payee' => __('Paid to'),
            'reference' => __('Reference'),
            'branch' => __('Branch'),
            'account' => __('Paid from'),
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
                'date' => $e->expense_date->format('Y-m-d'),
                'type' => $e->type->name,
                'payee' => $e->payee ?: '—',
                'reference' => $e->reference ?: '—',
                'branch' => $e->branch?->name ?? '—',
                'account' => $e->companyAccount->name,
                'amount' => $e->amount_cents,
            ]);
    }

    /** Shared with the by-type summary so both agree on what counts. */
    public function query(ReportFilters $filters, User $user)
    {
        return Expense::query()
            ->visibleTo($user)
            ->where('status', ExpenseStatus::Posted)
            ->whereBetween('expense_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->expenseTypeId, fn ($q) => $q->where('expense_type_id', $filters->expenseTypeId));
    }
}
