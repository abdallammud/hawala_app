<?php

namespace App\Services\Transfers;

use App\Models\Transfer;
use App\Models\TransferCollection;
use App\Models\User;
use App\Services\Rates\RateService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Uncollected transfers — money the agent sent before the customer paid.
 * Tracked in a register, never in the ledger (BUILD.md §8).
 */
class TransferCollectionService
{
    public function __construct(private RateService $rates) {}

    /**
     * Admin override: stop tracking a transfer as uncollected. Ledger untouched;
     * any collections already recorded stay on file. Logged with the reason.
     */
    public function clearFlag(Transfer $transfer, User $by, string $reason): void
    {
        DB::transaction(function () use ($transfer, $by, $reason) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->is_uncollected) {
                return;
            }

            $fresh->update(['is_uncollected' => false]);

            activity()
                ->causedBy($by)
                ->performedOn($fresh)
                ->withProperties([
                    'reason' => $reason,
                    'outstanding_cents' => $fresh->outstandingCents(),
                    'collected_cents' => $fresh->collected_cents,
                ])
                ->log('Uncollected flag cleared');
        });
    }

    public function record(
        Transfer $transfer,
        User $by,
        int $originalAmountCents,
        string $currency,
        \DateTimeInterface $receivedAt,
        ?string $note = null,
    ): TransferCollection {
        return DB::transaction(function () use ($transfer, $by, $originalAmountCents, $currency, $receivedAt, $note) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            // Collections in another currency convert at the market rate —
            // no margin, since this isn't an FX sale (BUILD.md §7).
            $rate = $this->rates->current($currency);

            $usdCents = $currency === 'USD'
                ? $originalAmountCents
                : BigDecimal::of($originalAmountCents)->multipliedBy($rate)->toScale(0, RoundingMode::HALF_UP)->toInt();

            $collection = TransferCollection::create([
                'transfer_id' => $fresh->id,
                'amount_cents' => $usdCents,
                'currency' => $currency,
                'original_amount_cents' => $originalAmountCents,
                'rate' => (string) $rate,
                'received_at' => $receivedAt,
                'recorded_by' => $by->id,
                'note' => $note,
            ]);

            $collected = $fresh->collected_cents + $usdCents;
            $owed = $fresh->amount_cents + $fresh->fee_cents;

            $fresh->update([
                'collected_cents' => $collected,
                'fully_collected_at' => $collected >= $owed ? ($fresh->fully_collected_at ?? now()) : null,
            ]);

            return $collection;
        });
    }
}
