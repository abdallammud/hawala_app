<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceRequestApproval extends Model
{
    protected $fillable = ['balance_request_id', 'user_id', 'note'];

    public function balanceRequest(): BelongsTo
    {
        return $this->belongsTo(BalanceRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
