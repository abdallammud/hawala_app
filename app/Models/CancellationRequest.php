<?php

namespace App\Models;

use App\Enums\CancellationRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancellationRequest extends Model
{
    protected $fillable = ['transfer_id', 'requested_by', 'reason', 'status', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return [
            'status' => CancellationRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
