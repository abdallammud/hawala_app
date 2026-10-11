<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class ExpenseDecided extends Notification
{
    public function __construct(public Expense $expense) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $message = __('Your expense :number (:amount) was :decision by :who.', [
            'number' => $this->expense->number,
            'amount' => Money::format($this->expense->amount_cents),
            'decision' => strtolower($this->expense->status->label()),
            'who' => $this->expense->decidedBy?->name ?? '—',
        ]);

        if ($this->expense->decision_reason) {
            $message .= ' '.__('Reason: :reason', ['reason' => $this->expense->decision_reason]);
        }

        return ['expense_id' => $this->expense->id, 'message' => $message];
    }
}
