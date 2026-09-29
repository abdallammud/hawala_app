<?php

namespace App\Notifications;

use App\Models\BalanceRequest;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class BalanceRequestDecided extends Notification
{
    public function __construct(public BalanceRequest $balanceRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->balanceRequest->status->value === 'approved';

        return [
            'balance_request_id' => $this->balanceRequest->id,
            'kind' => $this->balanceRequest->kind->value,
            'status' => $this->balanceRequest->status->value,
            'amount' => Money::format($this->balanceRequest->amount_cents),
            'message' => $approved
                ? __(':kind for :amount was approved.', [
                    'kind' => $this->balanceRequest->kind->label(),
                    'amount' => Money::format($this->balanceRequest->amount_cents),
                ])
                : __(':kind for :amount was rejected: :reason', [
                    'kind' => $this->balanceRequest->kind->label(),
                    'amount' => Money::format($this->balanceRequest->amount_cents),
                    'reason' => $this->balanceRequest->note ?? __('no reason given'),
                ]),
        ];
    }
}
