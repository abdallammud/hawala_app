<?php

namespace App\Models;

use App\Enums\SmsEvent;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SmsTemplate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'event', 'body', 'to_sender', 'to_receiver', 'to_sending_agent', 'to_payout_agent', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'event' => SmsEvent::class,
            'to_sender' => 'boolean',
            'to_receiver' => 'boolean',
            'to_sending_agent' => 'boolean',
            'to_payout_agent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** True when at least one recipient toggle is on. */
    public function hasRecipients(): bool
    {
        return $this->to_sender || $this->to_receiver || $this->to_sending_agent || $this->to_payout_agent;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['event', 'body', 'to_sender', 'to_receiver', 'to_sending_agent', 'to_payout_agent', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
