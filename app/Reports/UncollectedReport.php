<?php

namespace App\Reports;

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class UncollectedReport extends Report
{
    public function key(): string
    {
        return 'uncollected';
    }

    public function title(): string
    {
        return __('Uncollected');
    }

    public function description(): string
    {
        return __('Transfers sent before the customer paid, and what is still owed.');
    }

    public function permission(): string
    {
        return 'reports.uncollected';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'agent'];
    }

    public function columns(): array
    {
        return [
            'code' => __('Code'),
            'date' => __('Date'),
            'agent' => __('Sending agent'),
            'customer' => __('Customer'),
            'phone' => __('Phone'),
            'owed' => __('Owed'),
            'collected' => __('Collected'),
            'outstanding' => __('Outstanding'),
            'days_open' => __('Days open'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['owed', 'collected', 'outstanding'];
    }

    public function numericColumns(): array
    {
        return [...$this->moneyColumns(), 'days_open'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return Transfer::query()
            ->visibleTo($user)
            ->with('sendingAgent')
            ->where('is_uncollected', true)
            ->whereNull('fully_collected_at')
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->agentId, fn ($q) => $q->where('sending_agent_id', $filters->agentId))
            ->oldest()
            ->get()
            ->map(fn (Transfer $t) => [
                'code' => $t->code,
                'date' => $t->created_at->format('Y-m-d'),
                'agent' => $t->sendingAgent->name,
                'customer' => $t->sender_name,
                'phone' => $t->sender_phone,
                'owed' => $t->amount_cents + $t->fee_cents,
                'collected' => $t->collected_cents,
                'outstanding' => $t->outstandingCents(),
                'days_open' => $t->created_at->diffInDays(now()),
            ]);
    }
}
