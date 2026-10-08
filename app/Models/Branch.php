<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Branch extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'city', 'is_payout_point', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_payout_point' => 'boolean',
        ];
    }

    /** Branches a transfer can be sent to. */
    public function scopePayoutPoints($query)
    {
        return $query->where('is_active', true)->where('is_payout_point', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasActiveUsers(): bool
    {
        return $this->users()->where('is_active', true)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'city', 'is_payout_point', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
