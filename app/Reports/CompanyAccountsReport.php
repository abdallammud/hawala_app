<?php

namespace App\Reports;

use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Collection;

class CompanyAccountsReport extends Report
{
    public function key(): string
    {
        return 'company-accounts';
    }

    public function title(): string
    {
        return __('Company accounts');
    }

    public function description(): string
    {
        return __('Balance and movement per company account over the period.');
    }

    public function permission(): string
    {
        return 'reports.company_accounts';
    }

    public function columns(): array
    {
        return [
            'account' => __('Account'),
            'location' => __('Location'),
            'in' => __('In'),
            'out' => __('Out'),
            'net' => __('Net movement'),
            'balance' => __('Balance now'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['in', 'out', 'net', 'balance'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        return CompanyAccount::query()
            ->with('ledgerAccount')
            ->orderBy('name')
            ->get()
            ->map(function (CompanyAccount $account) use ($filters) {
                $ledgerAccountId = $account->ledgerAccount?->id;

                $lines = $ledgerAccountId
                    ? JournalLine::query()
                        ->where('ledger_account_id', $ledgerAccountId)
                        ->whereBetween('created_at', [$filters->from, $filters->to])
                        ->get()
                    : collect();

                // Company accounts are debit-normal: + is money in.
                $in = (int) $lines->where('amount_cents', '>', 0)->sum('amount_cents');
                $out = (int) abs($lines->where('amount_cents', '<', 0)->sum('amount_cents'));

                return [
                    'account' => $account->name,
                    'location' => ucfirst($account->location),
                    'in' => $in,
                    'out' => $out,
                    'net' => $in - $out,
                    'balance' => $account->ledgerAccount?->balance_cents ?? 0,
                ];
            })
            ->values();
    }
}
