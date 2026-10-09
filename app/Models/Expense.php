<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use LogsActivity;

    protected $fillable = [
        'expense_type_id', 'branch_id', 'company_account_id',
        'amount_cents', 'original_currency', 'original_amount_cents', 'rate',
        'expense_date', 'payee', 'reference', 'description',
        'status', 'journal_entry_id', 'void_journal_entry_id', 'voided_by', 'voided_at', 'void_reason',
        'created_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'expense_date' => 'date',
            'voided_at' => 'datetime',
            'amount_cents' => 'integer',
            'original_amount_cents' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'expense_type_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->status === ExpenseStatus::Voided;
    }

    /**
     * Own / branch / all, the same three scopes as transfers. A user with
     * none of them sees nothing rather than everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('expenses.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            if ($user->can('expenses.view_own')) {
                $q->orWhere('created_by', $user->id);
            }

            if ($user->can('expenses.view_branch') && $user->branch_id) {
                $q->orWhere('branch_id', $user->branch_id);
            }

            $q->orWhereRaw('1 = 0');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['expense_type_id', 'amount_cents', 'expense_date', 'payee', 'reference', 'description', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
