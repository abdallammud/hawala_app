<?php

namespace App\Notifications;

use App\Models\Transfer;
use Illuminate\Notifications\Notification;

class ClaimExpired extends Notification
{
    public function __construct(public Transfer $transfer, public string $agentName) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'transfer_id' => $this->transfer->id,
            'code' => $this->transfer->code,
            'message' => __('Claim on :code by :agent expired — back in the queue.', [
                'code' => $this->transfer->code,
                'agent' => $this->agentName,
            ]),
        ];
    }
}
