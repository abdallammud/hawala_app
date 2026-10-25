<?php

namespace App\Reports;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class TransfersReport extends Report
{
    public function key(): string
    {
        return 'transfers';
    }

    public function title(): string
    {
        return __('Transfers');
    }

    public function description(): string
    {
        return __('Every transfer with its agents, amounts, fee and commission split.');
    }

    public function permission(): string
    {
        return 'reports.transfers';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'branch', 'agent', 'status'];
    }

    public function columns(): array
    {
        return [
            'code' => __('Code'),
            'date' => __('Date'),
            'sending_agent' => __('Sending agent'),
            'payout_agent' => __('Payout agent'),
            'branch' => __('Branch'),
            'sender' => __('Sender'),
            'receiver' => __('Receiver'),
            'currency' => __('Currency'),
            'original' => __('Original'),
            'amount' => __('Amount'),
            'fee' => __('Fee'),
            'sending_share' => __('Sending share'),
            'payout_share' => __('Payout share'),
            'company_share' => __('Company share'),
            'status' => __('Status'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount', 'fee', 'sending_share', 'payout_share', 'company_share'];
    }

    public function numericColumns(): array
    {
        return [...$this->moneyColumns(), 'original'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return Transfer::query()
            ->reportableBy($user)
            ->with(['sendingAgent', 'claimedBy', 'paidBy', 'branch'])
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            // Anyone who touched the transfer: sent it, claimed it or paid it.
            ->when($filters->agentId, fn ($q) => $q->where(fn ($q) => $q
                ->where('sending_agent_id', $filters->agentId)
                ->orWhere('claimed_by', $filters->agentId)
                ->orWhere('paid_by', $filters->agentId)))
            ->when($filters->status, fn ($q) => $q->where('status', $filters->status))
            ->latest()
            ->get()
            ->map(fn (Transfer $t) => [
                'code' => $t->code,
                'date' => $t->created_at->toLocal()->format('Y-m-d H:i'),
                'sending_agent' => $t->sendingAgent->name,
                'payout_agent' => $t->paidBy?->name ?? $t->claimedBy?->name ?? '—',
                'branch' => $t->branch->name,
                'sender' => $t->sender_name,
                'receiver' => $t->receiver_name.' ('.$t->receiver_phone.')',
                'currency' => $t->original_currency,
                'original' => number_format($t->original_amount_cents / 100, 2, '.', ''),
                'amount' => $t->amount_cents,
                'fee' => $t->fee_cents,
                'sending_share' => $t->sending_share_cents,
                'payout_share' => $t->payout_share_cents ?? 0,
                'company_share' => $t->company_share_cents ?? 0,
                'status' => $t->status->label(),
            ]);
    }

    public static function statusOptions(): array
    {
        return TransferStatus::options();
    }
}
