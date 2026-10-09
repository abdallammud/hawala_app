<?php

namespace App\Enums;

enum JournalType: string
{
    case TopupPaid = 'topup_paid';
    case TopupCredit = 'topup_credit';
    case CreditRepayment = 'credit_repayment';
    case CommissionMove = 'commission_move';
    case CommissionWithdraw = 'commission_withdraw';
    case TransferSent = 'transfer_sent';
    case TransferPaid = 'transfer_paid';
    case TransferCancelled = 'transfer_cancelled';
    case TransferRejected = 'transfer_rejected';
    case PayoutFunding = 'payout_funding';
    case PayoutReturn = 'payout_return';
    case CompanyTransfer = 'company_transfer';
    case ManualAdjustment = 'manual_adjustment';
    case Expense = 'expense';
    case ExpenseVoid = 'expense_void';
}
