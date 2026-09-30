<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimExpiry extends Model
{
    protected $fillable = ['transfer_id', 'user_id', 'expired_at'];

    protected function casts(): array
    {
        return ['expired_at' => 'datetime'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
