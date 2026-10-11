<?php

namespace App\Models;

use App\Enums\EmailEvent;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmailTemplate extends Model
{
    use LogsActivity;

    protected $fillable = ['event', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return [
            'event' => EmailEvent::class,
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['event', 'subject', 'body', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
