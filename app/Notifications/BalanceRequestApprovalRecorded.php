<?php

namespace App\Notifications;

use App\Models\BalanceRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class BalanceRequestApprovalRecorded extends Notification
{
    public function __construct(public BalanceRequest $balanceRequest, public User $approver, public int $given) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $request = $this->balanceRequest;

        return [
            'balance_request_id' => $request->id,
            'kind' => $request->kind->value,
            'amount' => Money::format($request->amount_cents),
            'message' => __(':approver approved :kind for :amount (:given of :required). One more approval is needed.', [
                'approver' => $this->approver->name,
                'kind' => $request->kind->label(),
                'amount' => Money::format($request->amount_cents),
                'given' => $this->given,
                'required' => $request->approvals_required,
            ]),
        ];
    }
}
