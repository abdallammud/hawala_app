<?php

namespace App\Enums;

enum SmsStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Sent => 'green',
            self::Failed => 'red',
        };
    }
}
