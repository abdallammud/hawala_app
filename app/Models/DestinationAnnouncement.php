<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationAnnouncement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['branch_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
