<?php

namespace App\Notifications;

use App\Models\Transfer;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class TransferAssigned extends Notification
{
    public function __construct(public Transfer $transfer) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'transfer_id' => $this->transfer->id,
            'code' => $this->transfer->code,
            'message' => __('Transfer :code for :amount was assigned to you.', [
                'code' => $this->transfer->code,
                'amount' => Money::format($this->transfer->amount_cents),
            ]),
        ];
    }
}
