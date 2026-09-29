<?php

namespace App\Enums;

use App\Models\User;

enum BalanceRequestKind: string
{
    case TopupPaid = 'topup_paid';
    case TopupCredit = 'topup_credit';
    case CreditRepayment = 'credit_repayment';
    case PayoutFunding = 'payout_funding';
    case PayoutReturn = 'payout_return';
    case CommissionMove = 'commission_move';
    case CommissionWithdraw = 'commission_withdraw';

    public function label(): string
    {
        return match ($this) {
            self::TopupPaid => __('Top-up (paid)'),
            self::TopupCredit => __('Top-up (credit)'),
            self::CreditRepayment => __('Credit repayment'),
            self::PayoutFunding => __('Payout funding'),
            self::PayoutReturn => __('Payout return'),
            self::CommissionMove => __('Commission move to send balance'),
            self::CommissionWithdraw => __('Commission withdrawal'),
        };
    }

    /** The permission a decider needs to approve this kind. */
    public function approvePermission(): string
    {
        return match ($this) {
            self::TopupPaid => 'balances.approve',
            self::TopupCredit => 'balances.give_credit',
            self::CreditRepayment => 'balances.record_repayment',
            self::PayoutFunding, self::PayoutReturn => 'balances.fund_payout',
            self::CommissionMove => 'commission.approve_move',
            self::CommissionWithdraw => 'commission.approve_withdraw',
        };
    }

    public function requiresCompanyAccount(): bool
    {
        return match ($this) {
            self::TopupCredit, self::CommissionMove => false,
            default => true,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    /**
     * Kinds a user may submit from the "New request" screen. Commission
     * kinds are deliberately excluded — they have their own dedicated
     * Commission screen.
     */
    public static function requestableBy(User $user): array
    {
        $kinds = match ($user->type) {
            UserType::SendingAgent => [self::TopupPaid, self::TopupCredit],
            UserType::PayoutAgent => [self::PayoutFunding, self::PayoutReturn],
            UserType::Staff => [self::TopupPaid, self::TopupCredit, self::CreditRepayment, self::PayoutFunding, self::PayoutReturn],
        };

        return collect($kinds)->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
