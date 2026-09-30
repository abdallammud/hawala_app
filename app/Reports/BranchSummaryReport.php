<?php

namespace App\Reports;

use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class BranchSummaryReport extends Report
{
    public function key(): string
    {
        return 'branch-summary';
    }

    public function title(): string
    {
        return __('Branch summary');
    }

    public function description(): string
    {
        return __('Count and value per branch, broken down by status.');
    }

    public function permission(): string
    {
        return 'reports.branch_summary';
    }

    public function columns(): array
    {
        return [
            'branch' => __('Branch'),
            'queued_count' => __('Queued'),
            'queued_amount' => __('Queued value'),
            'claimed_count' => __('Claimed'),
            'claimed_amount' => __('Claimed value'),
            'paid_count' => __('Paid'),
            'paid_amount' => __('Paid value'),
            'cancelled_count' => __('Cancelled'),
            'cancelled_amount' => __('Cancelled value'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['queued_amount', 'claimed_amount', 'paid_amount', 'cancelled_amount'];
    }

    public function numericColumns(): array
    {
        return [...$this->moneyColumns(), 'queued_count', 'claimed_count', 'paid_count', 'cancelled_count'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $transfers = Transfer::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->get()
            ->groupBy('branch_id');

        return Branch::query()->orderBy('name')->get()->map(function (Branch $branch) use ($transfers) {
            $rows = $transfers->get($branch->id, collect());

            $for = fn (TransferStatus ...$statuses) => $rows->filter(
                fn (Transfer $t) => in_array($t->status, $statuses, true)
            );

            $queued = $for(TransferStatus::Queued, TransferStatus::AwaitingApproval);
            $claimed = $for(TransferStatus::Claimed);
            $paid = $for(TransferStatus::Paid);
            $cancelled = $for(TransferStatus::Cancelled, TransferStatus::Rejected);

            return [
                'branch' => $branch->name,
                'queued_count' => $queued->count(),
                'queued_amount' => (int) $queued->sum('amount_cents'),
                'claimed_count' => $claimed->count(),
                'claimed_amount' => (int) $claimed->sum('amount_cents'),
                'paid_count' => $paid->count(),
                'paid_amount' => (int) $paid->sum('amount_cents'),
                'cancelled_count' => $cancelled->count(),
                'cancelled_amount' => (int) $cancelled->sum('amount_cents'),
            ];
        })->values();
    }

    public function totals(Collection $rows): array
    {
        $totals = parent::totals($rows);

        foreach (['queued_count', 'claimed_count', 'paid_count', 'cancelled_count'] as $column) {
            $totals[$column] = (int) $rows->sum($column);
        }

        return $totals;
    }
}
