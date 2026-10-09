<?php

namespace App\Reports;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Models\BalanceRequest;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Collection;

class CommissionsReport extends Report
{
    public function key(): string
    {
        return 'commissions';
    }

    public function title(): string
    {
        return __('Commissions');
    }

    public function description(): string
    {
        return __('What each agent earned, moved to their send balance, withdrew, and still holds.');
    }

    public function permission(): string
    {
        return 'reports.commissions';
    }

    public function columns(): array
    {
        return [
            'agent' => __('Agent'),
            'earned' => __('Earned'),
            'earned_sending' => __('…from sending'),
            'earned_payout' => __('…from paying out'),
            'moved' => __('Moved to send'),
            'withdrawn' => __('Withdrawn'),
            'balance' => __('Balance now'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['earned', 'earned_sending', 'earned_payout', 'moved', 'withdrawn', 'balance'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        // Agents only see themselves unless they can view everyone's.
        $scoped = $user->can('commission.view_all');

        $accounts = LedgerAccount::query()
            ->where('type', LedgerAccountType::AgentCommission->value)
            ->whereNotNull('owner_id')
            ->when(! $scoped, fn ($q) => $q->where('owner_id', $user->id))
            ->with('owner')
            ->get();

        $movements = BalanceRequest::query()
            ->whereIn('kind', [BalanceRequestKind::CommissionMove, BalanceRequestKind::CommissionWithdraw])
            ->where('status', BalanceRequestStatus::Approved)
            ->whereBetween('decided_at', [$filters->from, $filters->to])
            ->get()
            ->groupBy('user_id');

        return $accounts->map(function (LedgerAccount $account) use ($filters, $movements) {
            // Earned = credits to the commission account in the window.
            // Credit-normal, so a credit is a negative signed line.
            $earned = (int) abs(JournalLine::query()
                ->where('ledger_account_id', $account->id)
                ->where('amount_cents', '<', 0)
                ->whereBetween('created_at', [$filters->from, $filters->to])
                ->sum('amount_cents'));

            // The same credits, split by what earned them — matters when one
            // person both sends and pays out.
            $bySource = JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.ledger_account_id', $account->id)
                ->where('journal_lines.amount_cents', '<', 0)
                ->whereBetween('journal_lines.created_at', [$filters->from, $filters->to])
                ->whereIn('journal_entries.type', [JournalType::TransferSent->value, JournalType::TransferPaid->value])
                ->groupBy('journal_entries.type')
                ->selectRaw('journal_entries.type as source, SUM(journal_lines.amount_cents) as total')
                ->pluck('total', 'source');

            $mine = $movements->get($account->owner_id, collect());

            return [
                'agent' => $account->owner?->name ?? __('Unknown'),
                'earned' => $earned,
                'earned_sending' => (int) abs($bySource[JournalType::TransferSent->value] ?? 0),
                'earned_payout' => (int) abs($bySource[JournalType::TransferPaid->value] ?? 0),
                'moved' => (int) $mine->where('kind', BalanceRequestKind::CommissionMove)->sum('amount_cents'),
                'withdrawn' => (int) $mine->where('kind', BalanceRequestKind::CommissionWithdraw)->sum('amount_cents'),
                'balance' => $account->balance_cents,
            ];
        })
            ->filter(fn (array $row) => $row['earned'] !== 0 || $row['balance'] !== 0 || $row['moved'] !== 0 || $row['withdrawn'] !== 0)
            ->sortBy('agent')
            ->values();
    }
}
