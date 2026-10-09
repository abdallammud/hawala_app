<?php

namespace App\Reports;

use App\Models\Customer;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every transfer one customer has sent, with totals.
 */
class CustomerStatementReport extends Report
{
    public function key(): string
    {
        return 'customer-statement';
    }

    public function title(): string
    {
        return __('Customer statement');
    }

    public function description(): string
    {
        return __('Every transfer one customer sent in the period. Pick the customer first.');
    }

    public function permission(): string
    {
        return 'reports.customers';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'customer'];
    }

    public function columns(): array
    {
        return [
            'date' => __('Date'),
            'code' => __('Tracking number'),
            'receiver' => __('Receiver'),
            'destination' => __('Destination'),
            'branch' => __('Branch'),
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
        if ($filters->customerId === null) {
            return collect();
        }

        // Someone else's customer yields nothing rather than an error.
        $customer = Customer::query()->visibleTo($user)->find($filters->customerId);

        if ($customer === null) {
            return collect();
        }

        return $customer->transfers()
            ->reportableBy($user)
            ->with('branch')
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->oldest()
            ->get()
            ->map(fn (Transfer $t) => [
                'date' => $t->created_at->format('Y-m-d H:i'),
                'code' => $t->code,
                'receiver' => $t->receiver_name.' ('.$t->receiver_phone.')',
                'destination' => collect([$t->receiver_city, $t->receiver_province, $t->receiver_country])->filter()->implode(', ') ?: '—',
                'branch' => $t->branch->name,
                'amount' => $t->amount_cents,
                'fee' => $t->fee_cents,
                'status' => $t->status->label(),
            ]);
    }
}
