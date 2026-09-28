<?php

namespace App\Enums;

enum LedgerAccountType: string
{
    case Company = 'company';
    case AgentSend = 'agent_send';
    case AgentCredit = 'agent_credit';
    case AgentPayout = 'agent_payout';
    case AgentCommission = 'agent_commission';
    case TransfersPayable = 'transfers_payable';
    case FeeIncome = 'fee_income';
    case FxIncome = 'fx_income';
    case CommissionExpense = 'commission_expense';
    case BankCharges = 'bank_charges';
    case Adjustments = 'adjustments';

    /**
     * Natural side — BUILD.md §4. Determines how a signed journal line
     * (+ debit, − credit) moves the account's cached balance.
     */
    public function normalSide(): NormalSide
    {
        return match ($this) {
            self::Company, self::AgentCredit, self::AgentPayout,
            self::CommissionExpense, self::BankCharges, self::Adjustments => NormalSide::Debit,

            self::AgentSend, self::AgentCommission, self::TransfersPayable,
            self::FeeIncome, self::FxIncome => NormalSide::Credit,
        };
    }

    /**
     * Balances of these types must never go negative (agent_payout is
     * conditional on the payout.allow_negative setting — see LedgerService).
     */
    public function isAlwaysGuarded(): bool
    {
        return match ($this) {
            self::AgentSend, self::AgentCredit, self::AgentCommission => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Company account balance'),
            self::AgentSend => __('Send balance'),
            self::AgentCredit => __('Credit owed'),
            self::AgentPayout => __('Payout balance'),
            self::AgentCommission => __('Commission balance'),
            self::TransfersPayable => __('Waiting to be paid'),
            self::FeeIncome => __('Fee income'),
            self::FxIncome => __('FX income'),
            self::CommissionExpense => __('Commission expense'),
            self::BankCharges => __('Bank charges'),
            self::Adjustments => __('Adjustments'),
        };
    }
}
