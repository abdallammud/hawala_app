<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeeTier extends Model
{
    use LogsActivity;

    protected $fillable = ['from_cents', 'to_cents', 'rate_bp', 'flat_cents', 'cap_cents', 'sort'];

    protected function casts(): array
    {
        return [
            'from_cents' => 'integer',
            'to_cents' => 'integer',
            'rate_bp' => 'integer',
            'flat_cents' => 'integer',
            'cap_cents' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['from_cents', 'to_cents', 'rate_bp', 'flat_cents', 'cap_cents', 'sort'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
