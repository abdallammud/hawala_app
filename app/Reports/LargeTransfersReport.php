<?php

namespace App\Reports;

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class LargeTransfersReport extends Report
{
    public function key(): string
    {
        return 'large-transfers';
    }

    public function title(): string
    {
        return __('Large transfers');
    }

    public function description(): string
    {
        return __('Transfers at or above the large-transfer threshold in Settings.');
    }

    public function permission(): string
    {
        return 'reports.large_transfers';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch', 'agent'];
    }

    public function columns(): array
    {
        return [
            'code' => __('Code'),
            'date' => __('Date'),
            'agent' => __('Sending agent'),
            'branch' => __('Branch'),
            'receiver' => __('Receiver'),
            'amount' => __('Amount'),
            'fee' => __('Fee'),
            'status' => __('Status'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount', 'fee'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return Transfer::query()
            ->reportableBy($user)
            ->with(['sendingAgent', 'branch'])
            ->where('is_large', true)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->agentId, fn ($q) => $q->where('sending_agent_id', $filters->agentId))
            ->latest()
            ->get()
            ->map(fn (Transfer $t) => [
                'code' => $t->code,
                'date' => $t->created_at->format('Y-m-d H:i'),
                'agent' => $t->sendingAgent->name,
                'branch' => $t->branch->name,
                'receiver' => $t->receiver_name.' ('.$t->receiver_phone.')',
                'amount' => $t->amount_cents,
                'fee' => $t->fee_cents,
                'status' => $t->status->label(),
            ]);
    }
}
