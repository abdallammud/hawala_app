<?php

namespace App\Reports;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where the money went: one line per expense type.
 */
class ExpensesByTypeReport extends Report
{
    public function key(): string
    {
        return 'expenses-by-type';
    }

    public function title(): string
    {
        return __('Expenses by type');
    }

    public function description(): string
    {
        return __('Total spent on payroll, rent, bills and the rest, with each one’s share.');
    }

    public function permission(): string
    {
        return 'reports.expenses';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch'];
    }

    public function columns(): array
    {
        return [
            'type' => __('Type'),
            'count' => __('Expenses'),
            'amount' => __('Total (USD)'),
            'share' => __('Share'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount'];
    }

    public function numericColumns(): array
    {
        return ['count', 'amount', 'share'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $expenses = app(ExpensesReport::class)
            ->query(new ReportFilters($filters->from, $filters->to, branchId: $filters->branchId), $user)
            ->with('type')
            ->get();

        $grand = max(1, (int) $expenses->sum('amount_cents'));

        return $expenses
            ->groupBy('expense_type_id')
            ->map(fn (Collection $group) => [
                'type' => $group->first()->type->name,
                'count' => $group->count(),
                'amount' => (int) $group->sum('amount_cents'),
                // One decimal place, as text: it is not money.
                'share' => number_format($group->sum('amount_cents') * 100 / $grand, 1).'%',
            ])
            ->sortByDesc('amount')
            ->values();
    }

    public function totals(Collection $rows): array
    {
        return [
            'count' => (int) $rows->sum('count'),
            'amount' => (int) $rows->sum('amount'),
        ];
    }
}
