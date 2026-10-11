<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_notifications',
        'phone',
        'password',
        'type',
        'branch_id',
        'is_active',
        'send_commission_type',
        'send_commission_value',
        'payout_commission_type',
        'payout_commission_value',
        'max_credit_cents',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_notifications' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'type' => UserType::class,
            'send_commission_type' => CommissionType::class,
            'payout_commission_type' => CommissionType::class,
            'max_credit_cents' => 'integer',
        ];
    }

    /** True once the user has proved their authenticator app works. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(PayoutAccount::class);
    }

    public function balanceRequests(): HasMany
    {
        return $this->hasMany(BalanceRequest::class);
    }

    /**
     * True while any ledger balance is non-zero. Deactivation is blocked
     * while this is true.
     */
    public function hasNonZeroBalances(): bool
    {
        return LedgerAccount::query()
            ->where('owner_type', $this->getMorphClass())
            ->where('owner_id', $this->id)
            ->where('balance_cents', '!=', 0)
            ->exists();
    }

    /** Memoised sendBalanceCents(); false means "not looked up yet". */
    private int|null|false $sendBalanceMemo = false;

    /**
     * Send balance in cents, or null when this user is not someone who sends.
     *
     * "Everyone who sends needs balance" (BUILD.md §1) — and admins hold
     * `transfers.create` too, so an admin who sends draws on their own send
     * balance exactly like an agent. Anyone who can create a transfer
     * therefore sees the figure, showing $0.00 before their first top-up
     * rather than hiding it; only people who can never send (payout agents,
     * back-office staff without the permission) get null.
     *
     * Read-only on purpose: looking at a page must never create an account.
     * Memoised because the layout renders it on every request.
     */
    public function sendBalanceCents(): ?int
    {
        if ($this->sendBalanceMemo !== false) {
            return $this->sendBalanceMemo;
        }

        $cents = LedgerAccount::query()
            ->where('type', LedgerAccountType::AgentSend->value)
            ->where('owner_type', $this->getMorphClass())
            ->where('owner_id', $this->id)
            ->value('balance_cents');

        if ($cents !== null) {
            return $this->sendBalanceMemo = (int) $cents;
        }

        $sends = $this->type->sends() || $this->can('transfers.create');

        return $this->sendBalanceMemo = $sends ? 0 : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'email_notifications', 'phone', 'type', 'branch_id', 'is_active', 'send_commission_type', 'send_commission_value', 'payout_commission_type', 'payout_commission_value', 'max_credit_cents'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Record the commission types with each entry so the audit page can word a changed value. */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('context', [
            'send_commission_type' => $this->send_commission_type?->value,
            'payout_commission_type' => $this->payout_commission_type?->value,
        ]);
    }
}
