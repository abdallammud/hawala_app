<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RateFetchFailed extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'message' => __('Exchange rate fetch failed for all providers. Set manual rates until this recovers.'),
        ];
    }
}
