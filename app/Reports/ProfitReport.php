<?php

namespace App\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\NormalSide;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Profit straight off the ledger rather than off transfers, so manual
 * adjustments and bank charges are included and the figure reconciles
 * with the accounts.
 */
class ProfitReport extends Report
{
    public function key(): string
    {
        return 'profit';
    }

    public function title(): string
    {
        return __('Profit');
    }

    public function description(): string
    {
        return __('Income less costs, by day or month, taken from the ledger.');
    }

    public function permission(): string
    {
        return 'reports.profit';
    }

    public function availableFilters(): array
    {
        return ['from', 'to', 'group_by'];
    }

    public function columns(): array
    {
        return [
            'period' => __('Period'),
            'fee_income' => __('Fee income'),
            'fx_income' => __('FX income'),
            'commission_expense' => __('Commission'),
            'bank_charges' => __('Bank charges'),
            'adjustments' => __('Adjustments'),
            'expenses' => __('Expenses'),
            'net' => __('Net'),
        ];
    }

    public function moneyColumns(): array
    {
        return ['fee_income', 'fx_income', 'commission_expense', 'bank_charges', 'adjustments', 'expenses', 'net'];
    }

    public function rows(ReportFilters $filters, User $user): Collection
    {
        $types = [
            LedgerAccountType::FeeIncome,
            LedgerAccountType::FxIncome,
            LedgerAccountType::CommissionExpense,
            LedgerAccountType::BankCharges,
            LedgerAccountType::Adjustments,
            LedgerAccountType::Expenses,
        ];

        $monthly = $filters->groupBy === 'month';
        $format = $monthly ? 'Y-m' : 'Y-m-d';

        $lines = JournalLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->whereIn('ledger_accounts.type', array_map(fn ($t) => $t->value, $types))
            ->whereNull('ledger_accounts.owner_id')
            ->whereBetween('journal_lines.created_at', [$filters->from, $filters->to])
            ->select([
                'journal_lines.amount_cents',
                'journal_lines.created_at',
                'ledger_accounts.type',
                'ledger_accounts.normal_side',
            ])
            ->get();

        return $lines
            ->groupBy(fn ($line) => $line->created_at->toLocal()->format($format))
            ->map(function (Collection $group, string $period) {
                $sum = function (LedgerAccountType $type) use ($group) {
                    return (int) $group
                        ->where('type', $type->value)
                        // Natural sign: debit-normal accounts grow with +,
                        // credit-normal ones with − (BUILD.md §4).
                        ->sum(fn ($l) => $l->normal_side === NormalSide::Debit->value
                            ? $l->amount_cents
                            : -$l->amount_cents);
                };

                $feeIncome = $sum(LedgerAccountType::FeeIncome);
                $fxIncome = $sum(LedgerAccountType::FxIncome);
                $commission = $sum(LedgerAccountType::CommissionExpense);
                $bankCharges = $sum(LedgerAccountType::BankCharges);
                $adjustments = $sum(LedgerAccountType::Adjustments);
                $expenses = $sum(LedgerAccountType::Expenses);

                return [
                    'period' => $period,
                    'fee_income' => $feeIncome,
                    'fx_income' => $fxIncome,
                    'commission_expense' => $commission,
                    'bank_charges' => $bankCharges,
                    'adjustments' => $adjustments,
                    'expenses' => $expenses,
                    'net' => $feeIncome + $fxIncome - $commission - $bankCharges - $adjustments - $expenses,
                ];
            })
            ->sortKeys()
            ->values();
    }
}
