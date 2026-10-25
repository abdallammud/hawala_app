<?php

namespace App\Models;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BalanceRequest extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'amount_cents', 'original_currency', 'original_amount_cents', 'rate',
        'company_account_id', 'status', 'requested_by', 'decided_by', 'decided_at', 'note',
        'reference', 'journal_entry_id', 'approvals_required',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BalanceRequestKind::class,
            'status' => BalanceRequestStatus::class,
            'amount_cents' => 'integer',
            'original_amount_cents' => 'integer',
            'approvals_required' => 'integer',
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

    public function approvals(): HasMany
    {
        return $this->hasMany(BalanceRequestApproval::class)->orderBy('id');
    }

    public function hasApprovalFrom(User $user): bool
    {
        return $this->approvals->contains('user_id', $user->id);
    }

    /** Whether this user can still add an approval or a rejection to it. */
    public function awaitingDecisionFrom(User $user): bool
    {
        return $this->status === BalanceRequestStatus::Pending
            && $user->can($this->kind->approvePermission())
            && $user->id !== $this->requested_by
            && ! $this->hasApprovalFrom($user);
    }
}
