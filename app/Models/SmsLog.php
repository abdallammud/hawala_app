<?php

namespace App\Models;

use App\Enums\SmsEvent;
use App\Enums\SmsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = ['to', 'body', 'status', 'provider_response', 'transfer_id', 'event'];

    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'event' => SmsEvent::class,
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
