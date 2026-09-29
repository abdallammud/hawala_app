<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyTransfer extends Model
{
    protected $fillable = [
        'from_company_account_id', 'to_company_account_id', 'sent_cents', 'received_cents',
        'charges_cents', 'reference', 'transferred_at', 'created_by', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'date',
        ];
    }

    public function fromCompanyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'from_company_account_id');
    }

    public function toCompanyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class, 'to_company_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
