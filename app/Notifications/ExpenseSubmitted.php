<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Notifications\Notification;

class ExpenseSubmitted extends Notification
{
    public function __construct(public Expense $expense) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'expense_id' => $this->expense->id,
            'message' => __(':who added expense :number for :amount (:type) — it needs approval.', [
                'who' => $this->expense->createdBy->name,
                'number' => $this->expense->number,
                'amount' => Money::format($this->expense->amount_cents),
                'type' => $this->expense->type->name,
            ]),
        ];
    }
}
