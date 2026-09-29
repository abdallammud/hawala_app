<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'rate_to_usd', 'source', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'rate_to_usd' => 'decimal:8',
            'fetched_at' => 'datetime',
        ];
    }

    public function currencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency', 'code');
    }
}
