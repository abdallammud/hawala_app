<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No LogsActivity here — the trait's default migration types
 * activity_log.subject_id as an integer, incompatible with this model's
 * string primary key (currency code). Changes are still timestamped and,
 * being config-like reference data rather than money movements, don't need
 * the same audit trail as Users/Branches/AccountTypes.
 */
class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'is_active', 'manual_rate', 'sort'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'manual_rate' => 'decimal:8',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency', 'code');
    }
}
