<?php

namespace App\Reports;

use App\Enums\TransferStatus;
use App\Models\ClaimExpiry;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * BUILD.md §12 asks for two things under one heading: cancelled transfers
 * (who, why, was the fee refunded) and claim time-outs per payout agent.
 * They're shown as one list with a "kind" column so a manager can scan
 * both in one place and filter in a spreadsheet after export.
 */
class CancellationsReport extends Report
{
    public function key(): string
    {
        return 'cancellations';
    }

    public function title(): string
    {
        return __('Cancellations & time-outs');
    }

    public function description(): string
    {
        return __('Cancelled or rejected transfers, and claims that timed out back to the queue.');
    }

    public function permission(): string
    {
        return 'reports.cancellations';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch'];
    }

    public function columns(): array
    {
        return [
            'kind' => __('Kind'),
            'date' => __('Date'),
            'code' => __('Code'),
            'branch' => __('Branch'),
            'who' => __('Who'),
            'reason' => __('Reason'),
            'amount' => __('Amount'),
            'fee_refunded' => __('Fee refunded'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $cancelled = Transfer::query()
            ->visibleTo($user)
            ->with(['branch', 'cancelledBy'])
            ->whereIn('status', [TransferStatus::Cancelled, TransferStatus::Rejected])
            ->whereBetween('cancelled_at', [$filters->from, $filters->to])
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->get()
            ->map(fn (Transfer $t) => [
                'kind' => $t->status === TransferStatus::Rejected ? __('Rejected') : __('Cancelled'),
                'sort_at' => $t->cancelled_at,
                'date' => $t->cancelled_at?->format('Y-m-d H:i') ?? '—',
                'code' => $t->code,
                'branch' => $t->branch->name,
                'who' => $t->cancelledBy?->name ?? '—',
                'reason' => $t->cancel_reason ?? '—',
                'amount' => $t->amount_cents,
                'fee_refunded' => $t->fee_refunded ? __('Yes') : __('No'),
            ]);

        $expiries = ClaimExpiry::query()
            ->with(['transfer.branch', 'user'])
            ->whereBetween('expired_at', [$filters->from, $filters->to])
            ->when($filters->branchId, fn ($q) => $q->whereHas('transfer', fn ($t) => $t->where('branch_id', $filters->branchId)))
            ->get()
            // A payout agent shouldn't see expiries on transfers outside their scope.
            ->filter(fn (ClaimExpiry $e) => $e->transfer && $user->can('view', $e->transfer))
            ->map(fn (ClaimExpiry $e) => [
                'kind' => __('Claim timed out'),
                'sort_at' => $e->expired_at,
                'date' => $e->expired_at->format('Y-m-d H:i'),
                'code' => $e->transfer->code,
                'branch' => $e->transfer->branch->name,
                'who' => $e->user?->name ?? '—',
                'reason' => __('Claim expired'),
                'amount' => $e->transfer->amount_cents,
                'fee_refunded' => '—',
            ]);

        return $cancelled
            ->concat($expiries)
            ->sortByDesc('sort_at')
            ->map(fn (array $row) => collect($row)->except('sort_at')->all())
            ->values();
    }
}
