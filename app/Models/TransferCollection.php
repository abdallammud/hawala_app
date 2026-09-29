<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferCollection extends Model
{
    protected $fillable = [
        'transfer_id', 'amount_cents', 'currency', 'original_amount_cents', 'rate',
        'received_at', 'recorded_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'rate' => 'decimal:8',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
