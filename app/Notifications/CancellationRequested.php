<?php

namespace App\Notifications;

use App\Models\CancellationRequest;
use Illuminate\Notifications\Notification;

class CancellationRequested extends Notification
{
    public function __construct(public CancellationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'cancellation_request_id' => $this->request->id,
            'transfer_id' => $this->request->transfer_id,
            'code' => $this->request->transfer->code,
            'message' => __(':agent asked to cancel claimed transfer :code.', [
                'agent' => $this->request->requestedBy->name,
                'code' => $this->request->transfer->code,
            ]),
        ];
    }
}
