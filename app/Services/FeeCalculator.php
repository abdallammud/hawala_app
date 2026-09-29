<?php

namespace App\Services;

use App\Models\FeeTier;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * BUILD.md §5. Input: amount_cents (USD the receiver gets). Output:
 * fee_cents + a snapshot to freeze on the transfer at creation time
 * (CLAUDE.md money rule 8 — settings changes never change past transfers).
 */
class FeeCalculator
{
    /**
     * $mode overrides the fees.mode setting — used by the fee tier preview
     * to show both modes side by side without touching global settings.
     */
    public function calculate(int $amountCents, ?string $mode = null): array
    {
        $tiers = FeeTier::query()->orderBy('from_cents')->get();
        $mode ??= Settings::get('fees.mode', 'whole');
        $minFeeCents = Settings::get('fees.min_fee_cents', 0);

        $fee = $mode === 'marginal'
            ? $this->marginal($amountCents, $tiers)
            : $this->whole($amountCents, $tiers);

        $fee = max($fee, $minFeeCents);

        return [
            'fee_cents' => $fee,
            'snapshot' => [
                'mode' => $mode,
                'min_fee_cents' => $minFeeCents,
                'tiers' => $tiers->map(fn (FeeTier $t) => [
                    'from_cents' => $t->from_cents,
                    'to_cents' => $t->to_cents,
                    'rate_bp' => $t->rate_bp,
                    'flat_cents' => $t->flat_cents,
                    'cap_cents' => $t->cap_cents,
                ])->all(),
            ],
        ];
    }

    private function whole(int $amount, Collection $tiers): int
    {
        $tier = $tiers->first(fn (FeeTier $t) => $this->inTier($amount, $t));

        if (! $tier) {
            return 0;
        }

        $fee = $this->percentOf($amount, $tier->rate_bp) + $tier->flat_cents;

        if ($tier->cap_cents !== null) {
            $fee = min($fee, $tier->cap_cents);
        }

        return $fee;
    }

    private function marginal(int $amount, Collection $tiers): int
    {
        $fee = 0;
        $containingTier = null;

        foreach ($tiers as $tier) {
            $to = $tier->to_cents ?? PHP_INT_MAX;
            $slice = min($amount, $to) - $tier->from_cents;

            if ($slice > 0) {
                $fee += $this->percentOf($slice, $tier->rate_bp);
            }

            if ($this->inTier($amount, $tier)) {
                $containingTier = $tier;
            }
        }

        if ($containingTier) {
            $fee += $containingTier->flat_cents;
        }

        return $fee;
    }

    private function inTier(int $amount, FeeTier $tier): bool
    {
        return $amount >= $tier->from_cents && ($tier->to_cents === null || $amount < $tier->to_cents);
    }

    private function percentOf(int $cents, int $rateBp): int
    {
        return BigDecimal::of($cents)
            ->multipliedBy($rateBp)
            ->dividedBy(10000, 0, RoundingMode::HALF_UP)
            ->toInt();
    }
}
