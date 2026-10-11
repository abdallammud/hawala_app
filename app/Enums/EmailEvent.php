<?php

namespace App\Enums;

/**
 * The moments the system emails someone. Recipients are fixed by the event;
 * the subject and body are editable (Setup → Email templates), and the
 * defaults here are what "reset to default" and a fresh install use.
 *
 * The default texts leave out phone and account numbers on purpose: email is
 * less private than the system. People who need them log in, and an admin may
 * add the placeholders if the company wants them in the message.
 */
enum EmailEvent: string
{
    case TransferSent = 'transfer_sent';
    case TransferPaid = 'transfer_paid';
    case TransferCancelled = 'transfer_cancelled';
    case TopupApproved = 'topup_approved';
    case ClaimExpired = 'claim_expired';
    case LargeTransfer = 'large_transfer';
    case ExpenseSubmitted = 'expense_submitted';
    case ExpenseApproved = 'expense_approved';
    case ExpenseRejected = 'expense_rejected';

    public function label(): string
    {
        return match ($this) {
            self::TransferSent => __('New transfer'),
            self::TransferPaid => __('Transfer paid'),
            self::TransferCancelled => __('Transfer cancelled'),
            self::TopupApproved => __('Top-up approved'),
            self::ClaimExpired => __('Claim expired'),
            self::LargeTransfer => __('Large transfer'),
            self::ExpenseSubmitted => __('Expense needs approval'),
            self::ExpenseApproved => __('Expense approved'),
            self::ExpenseRejected => __('Expense rejected'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TransferSent => __('A sending agent records a transfer.'),
            self::TransferPaid => __('A payout agent marks a transfer paid.'),
            self::TransferCancelled => __('A transfer is cancelled or rejected.'),
            self::TopupApproved => __('An agent balance top-up is approved.'),
            self::ClaimExpired => __('A claim times out and the transfer returns to the queue.'),
            self::LargeTransfer => __('A transfer at or above the large-transfer limit is created.'),
            self::ExpenseSubmitted => __('Someone records a new expense.'),
            self::ExpenseApproved => __('An expense is approved.'),
            self::ExpenseRejected => __('An expense is rejected.'),
        };
    }

    /** Who gets it — fixed, so a template can never go to the wrong people. */
    public function recipients(): string
    {
        return match ($this) {
            self::TransferSent => __('Every active payout agent of the destination branch'),
            self::TransferPaid => __('The sending agent, with the receipt attached'),
            self::TransferCancelled => __('The sending agent'),
            self::TopupApproved => __('The agent whose balance was topped up'),
            self::ClaimExpired => __('The payout agent whose claim expired'),
            self::LargeTransfer => __('Users who can approve large transfers'),
            self::ExpenseSubmitted => __('Users who can approve expenses (not the person who added it)'),
            self::ExpenseApproved, self::ExpenseRejected => __('The person who added the expense'),
        };
    }

    /** Whether the transfer receipt PDF goes with this email. */
    public function attachesReceipt(): bool
    {
        return $this === self::TransferPaid;
    }

    /**
     * The {placeholders} this event can fill, without braces. Anything else in
     * a template is a typo and is refused when it is saved.
     *
     * @return string[]
     */
    public function placeholders(): array
    {
        $common = ['recipient_name', 'company_name', 'link'];

        $transfer = [
            'code', 'amount', 'fee', 'sender_name', 'receiver_name', 'sender_phone', 'receiver_phone',
            'branch', 'agent_name', 'payout_agent_name', 'date', 'status',
        ];

        $expense = [
            'expense_number', 'amount', 'expense_type', 'payee', 'account', 'date',
            'created_by', 'decided_by', 'reason',
        ];

        return array_merge($common, match ($this) {
            self::TransferSent, self::TransferPaid, self::ClaimExpired, self::LargeTransfer => $transfer,
            self::TransferCancelled => [...$transfer, 'reason'],
            self::TopupApproved => ['amount', 'kind', 'date', 'decided_by'],
            self::ExpenseSubmitted, self::ExpenseApproved, self::ExpenseRejected => $expense,
        });
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::TransferSent => 'New transfer {code} for {branch} — {amount}',
            self::TransferPaid => 'Transfer {code} has been paid',
            self::TransferCancelled => 'Transfer {code} was cancelled',
            self::TopupApproved => 'Your top-up of {amount} was approved',
            self::ClaimExpired => 'Your claim on transfer {code} has expired',
            self::LargeTransfer => 'Large transfer {code} — {amount}',
            self::ExpenseSubmitted => 'Expense {expense_number} needs approval — {amount}',
            self::ExpenseApproved => 'Expense {expense_number} was approved',
            self::ExpenseRejected => 'Expense {expense_number} was rejected',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::TransferSent => "Hello {recipient_name},\n\nA new transfer is waiting to be paid out at {branch}.\n\nTracking number: {code}\nAmount to pay out: {amount}\nReceiver: {receiver_name}\nSent by: {agent_name}\n\nOpen the payout queue to claim it:\n{link}\n\n{company_name}",
            self::TransferPaid => "Hello {recipient_name},\n\nTransfer {code} to {receiver_name} ({amount}) was paid out by {payout_agent_name} on {date}.\n\nThe receipt is attached to this email.\n\nView the transfer:\n{link}\n\n{company_name}",
            self::TransferCancelled => "Hello {recipient_name},\n\nTransfer {code} to {receiver_name} ({amount}) was cancelled.\nReason: {reason}\n\nView the transfer:\n{link}\n\n{company_name}",
            self::TopupApproved => "Hello {recipient_name},\n\nYour {kind} of {amount} was approved by {decided_by} on {date}. It is now in your balance.\n\n{link}\n\n{company_name}",
            self::ClaimExpired => "Hello {recipient_name},\n\nYou claimed transfer {code} ({amount} for {receiver_name}) but did not mark it paid in time, so it went back to the queue at {branch}.\n\nIf you still have it to pay, open the queue and claim it again:\n{link}\n\n{company_name}",
            self::LargeTransfer => "Hello {recipient_name},\n\nA large transfer was recorded.\n\nTracking number: {code}\nAmount: {amount}\nSent by: {agent_name}\nDestination: {branch}\nStatus: {status}\n\nView the transfer:\n{link}\n\n{company_name}",
            self::ExpenseSubmitted => "Hello {recipient_name},\n\n{created_by} added an expense that needs your approval.\n\nNumber: {expense_number}\nType: {expense_type}\nAmount: {amount}\nPaid to: {payee}\nPaid from: {account}\n\nReview it here:\n{link}\n\n{company_name}",
            self::ExpenseApproved => "Hello {recipient_name},\n\nYour expense {expense_number} ({amount}, {expense_type}) was approved by {decided_by}.\nNote: {reason}\n\n{link}\n\n{company_name}",
            self::ExpenseRejected => "Hello {recipient_name},\n\nYour expense {expense_number} ({amount}, {expense_type}) was rejected by {decided_by}.\nReason: {reason}\n\n{link}\n\n{company_name}",
        };
    }
}
