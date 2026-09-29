<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name',
        'email',
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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'type' => UserType::class,
            'send_commission_type' => CommissionType::class,
            'payout_commission_type' => CommissionType::class,
            'max_credit_cents' => 'integer',
        ];
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'type', 'branch_id', 'is_active', 'send_commission_type', 'send_commission_value', 'payout_commission_type', 'payout_commission_value', 'max_credit_cents'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
