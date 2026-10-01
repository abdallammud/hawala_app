<?php

namespace App\Models;

use App\Enums\QuoteMode;
use App\Enums\TransferStatus;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transfer extends Model
{
    protected $fillable = [
        'code', 'idempotency_key', 'sending_agent_id', 'branch_id',
        'sender_name', 'sender_phone', 'receiver_name', 'receiver_phone',
        'receiver_account_type_id', 'receiver_account_number',
        'original_currency', 'quote_mode', 'original_amount_cents', 'market_rate', 'margin_bp', 'customer_rate',
        'amount_cents', 'fee_cents', 'fx_income_cents', 'total_debit_cents', 'collect_original_cents',
        'round_up_original_cents',
        'sending_share_cents', 'payout_share_cents', 'company_share_cents',
        'fee_snapshot', 'commission_snapshot', 'status', 'is_large',
        'claimed_by', 'claimed_at', 'claim_expires_at', 'assigned_by',
        'paid_by', 'payout_account_id', 'payout_reference', 'paid_at',
        'is_uncollected', 'collected_cents', 'fully_collected_at',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'fee_refunded', 'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'quote_mode' => QuoteMode::class,
            'fee_snapshot' => 'array',
            'commission_snapshot' => 'array',
            'is_large' => 'boolean',
            'is_uncollected' => 'boolean',
            'fee_refunded' => 'boolean',
            'market_rate' => 'decimal:8',
            'customer_rate' => 'decimal:8',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fully_collected_at' => 'datetime',
        ];
    }

    /** Short, unambiguous, human-readable — no vowels, so no accidental words. */
    public static function generateCode(): string
    {
        do {
            $code = 'HW-'.Str::upper(Str::random(5));
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function sendingAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sending_agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receiverAccountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'receiver_account_type_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(TransferCollection::class);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    /** What the customer still owes on an uncollected transfer. */
    public function outstandingCents(): int
    {
        return max(0, $this->amount_cents + $this->fee_cents - $this->collected_cents);
    }

    public function isClaimExpired(): bool
    {
        return $this->status === TransferStatus::Claimed
            && $this->claim_expires_at !== null
            && $this->claim_expires_at->isPast();
    }

    /**
     * Scope to what a user is allowed to see: own / branch / all
     * (BUILD.md §10). Applied by controllers and reports alike.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('transfers.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            if ($user->can('transfers.view_own')) {
                $q->orWhere('sending_agent_id', $user->id);
            }

            if ($user->can('transfers.view_branch') && $user->branch_id) {
                $q->orWhere('branch_id', $user->branch_id);
            }

            // A user with neither scope sees nothing rather than everything.
            $q->orWhereRaw('1 = 0');
        });
    }

    public static function largeThresholdCents(): int
    {
        return (int) Settings::get('fees.large_transfer_cents', 300000);
    }
}
