<?php

namespace App\Notifications;

use App\Models\BalanceRequest;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class BalanceRequestSubmitted extends Notification
{
    public function __construct(public BalanceRequest $balanceRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'balance_request_id' => $this->balanceRequest->id,
            'kind' => $this->balanceRequest->kind->value,
            'beneficiary' => $this->balanceRequest->user->name,
            'amount' => Money::format($this->balanceRequest->amount_cents),
            'message' => __(':beneficiary requested :kind for :amount.', [
                'beneficiary' => $this->balanceRequest->user->name,
                'kind' => $this->balanceRequest->kind->label(),
                'amount' => Money::format($this->balanceRequest->amount_cents),
            ]),
        ];
    }
}
