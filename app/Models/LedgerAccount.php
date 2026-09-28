<?php

namespace App\Models;

use App\Enums\LedgerAccountType;
use App\Enums\NormalSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Insert-only balances live here in cached, NATURAL sign — see BUILD.md §4.
 * Only App\Services\Ledger\LedgerService may change balance_cents.
 */
class LedgerAccount extends Model
{
    protected $fillable = ['type', 'normal_side', 'owner_type', 'owner_id', 'name', 'balance_cents'];

    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'normal_side' => NormalSide::class,
            'balance_cents' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
