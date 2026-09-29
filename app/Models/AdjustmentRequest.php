<?php

namespace App\Models;

use App\Enums\AdjustmentRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentRequest extends Model
{
    protected $fillable = [
        'debit_ledger_account_id', 'credit_ledger_account_id', 'amount_cents', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdjustmentRequestStatus::class,
            'amount_cents' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function debitLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'debit_ledger_account_id');
    }

    public function creditLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'credit_ledger_account_id');
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
