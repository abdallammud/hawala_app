<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class City extends Model
{
    use LogsActivity;

    protected $fillable = ['country', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active city names grouped by country — what the transfer form's City
     * dropdown filters on once a country is picked.
     *
     * @return array<string, list<string>>
     */
    public static function namesByCountry(): array
    {
        return static::query()->active()->orderBy('name')->get(['country', 'name'])
            ->groupBy('country')
            ->map(fn ($cities) => $cities->pluck('name')->all())
            ->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['country', 'name', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
