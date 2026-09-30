<?php

namespace App\Enums;

enum SmsEvent: string
{
    case TransferSent = 'transfer_sent';
    case TransferPaid = 'transfer_paid';
    case TransferCancelled = 'transfer_cancelled';
    case TopupApproved = 'topup_approved';
    case ClaimExpired = 'claim_expired';
    case LargeTransfer = 'large_transfer';

    public function label(): string
    {
        return match ($this) {
            self::TransferSent => __('Transfer sent'),
            self::TransferPaid => __('Transfer paid'),
            self::TransferCancelled => __('Transfer cancelled'),
            self::TopupApproved => __('Top-up approved'),
            self::ClaimExpired => __('Claim expired'),
            self::LargeTransfer => __('Large transfer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TransferSent => __('When an agent records a new transfer.'),
            self::TransferPaid => __('When a payout agent marks a transfer paid.'),
            self::TransferCancelled => __('When a transfer is cancelled or rejected.'),
            self::TopupApproved => __('When an agent balance top-up is approved.'),
            self::ClaimExpired => __('When a claim times out and returns to the queue.'),
            self::LargeTransfer => __('When a transfer is at or above the large-transfer limit.'),
        };
    }

    /** Which recipient toggles make sense for this event. */
    public function availableRecipients(): array
    {
        return match ($this) {
            self::TransferSent => ['to_sender', 'to_receiver', 'to_sending_agent'],
            self::TransferPaid => ['to_sender', 'to_receiver', 'to_sending_agent', 'to_payout_agent'],
            self::TransferCancelled => ['to_sender', 'to_sending_agent'],
            self::TopupApproved => ['to_sending_agent'],
            self::ClaimExpired => ['to_payout_agent'],
            self::LargeTransfer => ['to_sending_agent'],
        };
    }
}
