<?php

namespace App\Models;

use App\Enums\JournalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Insert-only — never updated or deleted. Corrections are new reversing
 * entries (see CLAUDE.md money rule 5).
 */
class JournalEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['uuid', 'type', 'reference_type', 'reference_id', 'created_by', 'approved_by', 'memo', 'created_at'];

    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'created_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
