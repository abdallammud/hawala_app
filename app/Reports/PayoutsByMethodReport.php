<?php

namespace App\Reports;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class PayoutsByMethodReport extends Report
{
    public function key(): string
    {
        return 'payouts-by-method';
    }

    public function title(): string
    {
        return __('Payouts by method');
    }

    public function description(): string
    {
        return __('How receivers were actually paid — EVC Plus, eDahab, bank, cash — per payout agent.');
    }

    public function permission(): string
    {
        return 'reports.payouts_by_method';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch'];
    }

    public function columns(): array
    {
        return [
            'method' => __('Method'),
            'agent' => __('Payout agent'),
            'count' => __('Payments'),
            'total' => __('Total paid'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['total'];
    }

    public function numericColumns(): array
    {
        return ['count', 'total'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return Transfer::query()
            ->reportableBy($user)
            ->with(['payoutAccount.accountType', 'paidBy'])
            ->where('status', TransferStatus::Paid)
            ->whereNotNull('payout_account_id')
            ->whereBetween('paid_at', [$filters->from, $filters->to])
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->get()
            ->groupBy(fn (Transfer $t) => $t->payoutAccount->accountType->name.'|'.($t->paidBy?->name ?? '—'))
            ->map(function (Collection $group, string $key) {
                [$method, $agent] = explode('|', $key);

                return [
                    'method' => $method,
                    'agent' => $agent,
                    'count' => $group->count(),
                    'total' => (int) $group->sum('amount_cents'),
                ];
            })
            ->sortBy([['method', 'asc'], ['agent', 'asc']])
            ->values();
    }

    public function totals(Collection $rows): array
    {
        return [
            'count' => (int) $rows->sum('count'),
            'total' => (int) $rows->sum('total'),
        ];
    }
}
