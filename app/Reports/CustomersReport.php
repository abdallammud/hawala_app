<?php

namespace App\Reports;

use App\Models\Customer;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One line per customer who sent something in the period.
 */
class CustomersReport extends Report
{
    public function key(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return __('Customers');
    }

    public function description(): string
    {
        return __('Who sent money in the period: how many transfers, how much, and the fees paid.');
    }

    public function permission(): string
    {
        return 'reports.customers';
    }

    public function columns(): array
    {
        return [
            'customer' => __('Customer'),
            'phone' => __('Phone'),
            'location' => __('Location'),
            'transfers' => __('Transfers'),
            'amount' => __('Sent'),
            'fees' => __('Fees'),
            'last' => __('Last transfer'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount', 'fees'];
    }

    public function numericColumns(): array
    {
        return [...$this->moneyColumns(), 'transfers'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $byCustomer = Transfer::query()
            ->reportableBy($user)
            ->whereNotNull('customer_id')
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->get(['customer_id', 'amount_cents', 'fee_cents', 'created_at'])
            ->groupBy('customer_id');

        return Customer::query()
            ->whereIn('id', $byCustomer->keys())
            ->get()
            ->map(function (Customer $c) use ($byCustomer) {
                $t = $byCustomer[$c->id];

                return [
                    'customer' => $c->name,
                    'phone' => $c->phoneLabel(),
                    'location' => $c->location() ?: '—',
                    'transfers' => $t->count(),
                    'amount' => (int) $t->sum('amount_cents'),
                    'fees' => (int) $t->sum('fee_cents'),
                    'last' => $t->max('created_at')->format('Y-m-d'),
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    public function totals(Collection $rows): array
    {
        return [
            'transfers' => (int) $rows->sum('transfers'),
            'amount' => (int) $rows->sum('amount'),
            'fees' => (int) $rows->sum('fees'),
        ];
    }
}
