<?php

namespace App\Models;

use App\Enums\EmailEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'event', 'to_email', 'to_name', 'user_id', 'subject', 'body', 'status', 'error', 'attempts',
        'attach_receipt', 'transfer_id', 'expense_id', 'balance_request_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'event' => EmailEvent::class,
            'attach_receipt' => 'boolean',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function color(): string
    {
        return match ($this->status) {
            self::SENT => 'green',
            self::FAILED => 'red',
            self::SKIPPED => 'slate',
            default => 'amber',
        };
    }

    /** How many emails went out in the last 24 hours — Gmail allows about 500 a day. */
    public static function sentInLastDay(): int
    {
        return static::query()->where('status', self::SENT)->where('sent_at', '>=', now()->subDay())->count();
    }
}
