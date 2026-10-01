<?php

namespace App\Services;

use App\Exceptions\Transfers\AmountTooSmallException;
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
        $tiers = $this->tiers();
        $mode ??= Settings::get('fees.mode', 'whole');
        $minFeeCents = Settings::get('fees.min_fee_cents', 0);

        return [
            'fee_cents' => $this->feeFor($amountCents, $tiers, $mode, $minFeeCents),
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

    /**
     * The other direction (BUILD.md §7, "customer pays" mode): the customer
     * hands over a fixed total and the fee comes out of it. Returns the
     * largest `amount` whose own fee still fits inside $budgetCents — plus
     * that fee and the usual snapshot, taken from `calculate()` itself so the
     * two directions can never disagree.
     *
     * Leftover cents (the budget can land between two whole-cent amounts)
     * stay with the company as FX income, never as a silent fee increase.
     *
     * @return array{amount_cents:int, fee_cents:int, snapshot:array}
     *
     * @throws AmountTooSmallException when nothing is left after the fee
     */
    public function inverse(int $budgetCents, ?string $mode = null): array
    {
        $tiers = $this->tiers();
        $mode ??= Settings::get('fees.mode', 'whole');
        $minFeeCents = Settings::get('fees.min_fee_cents', 0);

        $best = null;

        foreach ($this->inverseCandidates($budgetCents, $tiers, $mode, $minFeeCents) as $candidate) {
            // The closed-form candidate can sit a cent or two off once every
            // rounding step is applied, so verify a small window around it
            // with the real fee function and keep the largest that fits.
            for ($amount = $candidate + 4; $amount >= $candidate - 4; $amount--) {
                if ($amount < 1 || $amount > $budgetCents || ($best !== null && $amount <= $best)) {
                    continue;
                }

                if ($amount + $this->feeFor($amount, $tiers, $mode, $minFeeCents) <= $budgetCents) {
                    $best = $amount;
                    break;
                }
            }
        }

        if ($best === null) {
            throw AmountTooSmallException::forBudget($budgetCents);
        }

        return ['amount_cents' => $best] + $this->calculate($best, $mode);
    }

    private function tiers(): Collection
    {
        return FeeTier::query()->orderBy('from_cents')->get();
    }

    private function feeFor(int $amount, Collection $tiers, string $mode, int $minFeeCents): int
    {
        $fee = $mode === 'marginal'
            ? $this->marginal($amount, $tiers)
            : $this->whole($amount, $tiers);

        return max($fee, $minFeeCents);
    }

    /**
     * Amounts worth testing: where `amount + fee(amount) = budget` falls
     * inside each tier (fee is linear in amount within a tier, in both
     * modes), each tier's own edges, and the flat cases — minimum fee,
     * capped fee, no fee at all.
     *
     * @return array<int, int>
     */
    private function inverseCandidates(int $budget, Collection $tiers, string $mode, int $minFeeCents): array
    {
        $candidates = [$budget, $budget - $minFeeCents];
        $base = 0; // fee accumulated by the tiers below the current one (marginal mode)

        foreach ($tiers as $tier) {
            $rate = $tier->rate_bp;
            $fixed = $tier->flat_cents + ($mode === 'marginal' ? $base - $this->percentOf($tier->from_cents, $rate) : 0);

            // amount × (1 + rate) + fixed = budget
            $candidates[] = BigDecimal::of(max(0, $budget - $fixed))
                ->multipliedBy(10000)
                ->dividedBy(10000 + $rate, 0, RoundingMode::DOWN)
                ->toInt();

            if ($tier->cap_cents !== null) {
                $candidates[] = $budget - $tier->cap_cents;
            }

            $candidates[] = $tier->from_cents;

            if ($tier->to_cents !== null) {
                $candidates[] = $tier->to_cents - 1;
                $base += $this->percentOf($tier->to_cents - $tier->from_cents, $rate);
            }
        }

        return array_values(array_unique(array_filter($candidates, fn (int $c) => $c >= 1)));
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
