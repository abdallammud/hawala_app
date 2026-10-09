<?php

namespace App\Reports;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\LedgerAccountType;
use App\Models\BalanceRequest;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Collection;

class CreditOwedReport extends Report
{
    public function key(): string
    {
        return 'credit';
    }

    public function title(): string
    {
        return __('Credit owed');
    }

    public function description(): string
    {
        return __('Who owes the company money, how much has come back, and when they last paid.');
    }

    public function permission(): string
    {
        return 'reports.credit';
    }

    public function columns(): array
    {
        return [
            'agent' => __('Agent'),
            'given' => __('Credit given'),
            'repaid' => __('Repaid'),
            'outstanding' => __('Outstanding'),
            'limit' => __('Limit'),
            'last_repayment' => __('Last repayment'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['given', 'repaid', 'outstanding', 'limit'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $accounts = LedgerAccount::query()
            ->where('type', LedgerAccountType::AgentCredit->value)
            ->whereNotNull('owner_id')
            // Agents see their own credit, not everyone's.
            ->when(! $user->can('balances.view_all'), fn ($q) => $q->where('owner_id', $user->id))
            ->with('owner')
            ->get();

        $requests = BalanceRequest::query()
            ->whereIn('kind', [BalanceRequestKind::TopupCredit, BalanceRequestKind::CreditRepayment])
            ->where('status', BalanceRequestStatus::Approved)
            ->whereBetween('decided_at', [$filters->from, $filters->to])
            ->when(! $user->can('balances.view_all'), fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->groupBy('user_id');

        return $accounts->map(function (LedgerAccount $account) use ($requests) {
            $mine = $requests->get($account->owner_id, collect());
            $repayments = $mine->where('kind', BalanceRequestKind::CreditRepayment);

            return [
                'agent' => $account->owner?->name ?? __('Unknown'),
                'given' => (int) $mine->where('kind', BalanceRequestKind::TopupCredit)->sum('amount_cents'),
                'repaid' => (int) $repayments->sum('amount_cents'),
                'outstanding' => $account->balance_cents,
                'limit' => $account->owner?->max_credit_cents ?? 0,
                'last_repayment' => $repayments->max('decided_at')?->format('Y-m-d') ?? '—',
            ];
        })
            ->filter(fn (array $row) => $row['given'] !== 0 || $row['repaid'] !== 0 || $row['outstanding'] !== 0)
            ->sortByDesc('outstanding')
            ->values();
    }

    public function totals(Collection $rows): array
    {
        // A per-agent limit total would be meaningless, so leave it out.
        return [
            'given' => (int) $rows->sum('given'),
            'repaid' => (int) $rows->sum('repaid'),
            'outstanding' => (int) $rows->sum('outstanding'),
        ];
    }
}
