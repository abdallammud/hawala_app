<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Someone who sends money. Identified by phone number, company-wide.
 */
class Customer extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'phone', 'display_phone', 'country', 'city', 'province', 'created_by'];

    /**
     * Digits only, no "+" or leading "00": "+252 61-123", "00252 61 123" and
     * "25261123" are the same person. Local-format numbers ("061…") are left
     * as typed — guessing a country code could merge two different people.
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return preg_replace('/^00/', '', $digits);
    }

    /** The number as it should read on screen. */
    public function phoneLabel(): string
    {
        return $this->display_phone ?: '+'.$this->phone;
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Brussels, Brabant, Belgium" — whatever parts are known. */
    public function location(): string
    {
        return collect([$this->city, $this->province, $this->country])->filter()->implode(', ');
    }

    /**
     * Customers a user may see: everyone for `customers.view_all`, otherwise
     * the ones they registered or have sent a transfer for.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('customers.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('transfers', fn ($t) => $t->where('sending_agent_id', $user->id));
        });
    }

    /** Match on name or phone (phone compared digits-only). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = self::normalizePhone($term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', "%{$term}%");

            if ($digits !== '') {
                $q->orWhere('phone', 'like', "%{$digits}%");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'phone', 'country', 'city', 'province'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
