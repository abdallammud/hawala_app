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
    case ReceiverWaiting = 'receiver_waiting';
    case SenderPaid = 'sender_paid';

    public function label(): string
    {
        return match ($this) {
            self::TransferSent => __('Transfer sent'),
            self::TransferPaid => __('Transfer paid'),
            self::TransferCancelled => __('Transfer cancelled'),
            self::TopupApproved => __('Top-up approved'),
            self::ClaimExpired => __('Claim expired'),
            self::LargeTransfer => __('Large transfer'),
            self::ReceiverWaiting => __('Money waiting (to receiver)'),
            self::SenderPaid => __('Money paid (to sender)'),
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
            self::ReceiverWaiting => __('Tells the receiver money is waiting, with the tracker number, as soon as the transfer is on its way.'),
            self::SenderPaid => __('Tells the sender the money they sent has been paid to the receiver.'),
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
            self::ReceiverWaiting => ['to_receiver'],
            self::SenderPaid => ['to_sender'],
        };
    }
}
