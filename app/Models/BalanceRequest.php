<?php

namespace App\Models;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceRequest extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'amount_cents', 'original_currency', 'original_amount_cents', 'rate',
        'company_account_id', 'status', 'requested_by', 'decided_by', 'decided_at', 'note',
        'reference', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BalanceRequestKind::class,
            'status' => BalanceRequestStatus::class,
            'amount_cents' => 'integer',
            'original_amount_cents' => 'integer',
            'rate' => 'decimal:8',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
