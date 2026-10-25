<?php

namespace App\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\NormalSide;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Opening balance, every movement with a running balance, closing balance —
 * for one agent and one of their four balances.
 */
class AgentStatementReport extends Report
{
    public function key(): string
    {
        return 'agent-statement';
    }

    public function title(): string
    {
        return __('Agent statement');
    }

    public function description(): string
    {
        return __('Opening balance, every movement with a running balance, and the closing balance.');
    }

    public function permission(): string
    {
        return 'reports.agent_statement';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'agent', 'account_type'];
    }

    public function columns(): array
    {
        return [
            'date' => __('Date'),
            'type' => __('Type'),
            'memo' => __('Memo'),
            'amount' => __('Amount'),
            'running' => __('Running balance'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['amount', 'running'];
    }

    public static function accountTypeOptions(): array
    {
        return [
            LedgerAccountType::AgentSend->value => __('Send balance'),
            LedgerAccountType::AgentCredit->value => __('Credit owed'),
            LedgerAccountType::AgentPayout->value => __('Payout balance'),
            LedgerAccountType::AgentCommission->value => __('Commission balance'),
        ];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $agentId = $filters->agentId ?? $user->id;

        // Agents may only pull their own statement.
        if (! $user->can('reports.agent_statement')) {
            return collect();
        }

        if (! $user->can('transfers.view_all') && $agentId !== $user->id) {
            return collect();
        }

        $type = LedgerAccountType::tryFrom($filters->accountType ?? '') ?? LedgerAccountType::AgentSend;

        $account = LedgerAccount::query()
            ->where('type', $type->value)
            ->where('owner_type', (new User)->getMorphClass())
            ->where('owner_id', $agentId)
            ->first();

        if (! $account) {
            return collect();
        }

        $signed = fn (JournalLine $line) => $account->normal_side === NormalSide::Debit
            ? $line->amount_cents
            : -$line->amount_cents;

        // Opening balance = everything before the window.
        $opening = (int) JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->where('created_at', '<', $filters->from)
            ->get()
            ->sum($signed);

        $rows = collect([[
            'date' => $filters->fromDate(),
            'type' => __('Opening balance'),
            'memo' => '',
            'amount' => 0,
            'running' => $opening,
        ]]);

        $running = $opening;

        JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->with('journalEntry')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (JournalLine $line) use (&$rows, &$running, $signed) {
                $amount = $signed($line);
                $running += $amount;

                $rows->push([
                    'date' => $line->created_at->toLocal()->format('Y-m-d H:i'),
                    'type' => str($line->journalEntry->type->value)->replace('_', ' ')->title()->toString(),
                    'memo' => $line->journalEntry->memo ?? '',
                    'amount' => $amount,
                    'running' => $running,
                ]);
            });

        $rows->push([
            'date' => $filters->toDate(),
            'type' => __('Closing balance'),
            'memo' => '',
            'amount' => 0,
            'running' => $running,
        ]);

        return $rows->values();
    }

    /** A running-balance statement has no meaningful column totals. */
    public function totals(Collection $rows): array
    {
        return [];
    }
}
