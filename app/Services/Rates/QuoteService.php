<?php

namespace App\Services\Rates;

use App\Services\FeeCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * BUILD.md §7's transfer maths, exactly as documented there. The agent
 * enters original_amount in currency C — what the receiver should get, in C.
 */
class QuoteService
{
    public function __construct(
        private RateService $rates,
        private FeeCalculator $fees,
    ) {}

    public function quote(string $currency, int $originalAmountCents, int $marginBp): array
    {
        $marketRate = $this->rates->current($currency);

        // For USD, rate = 1 and margin never applies (BUILD.md §7).
        $customerRate = $currency === 'USD'
            ? $marketRate
            : $marketRate->multipliedBy(
                BigDecimal::one()->minus(BigDecimal::of($marginBp)->dividedBy(10000, 8, RoundingMode::HALF_UP))
            );

        $amountCents = BigDecimal::of($originalAmountCents)
            ->multipliedBy($customerRate)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();

        $feeResult = $this->fees->calculate($amountCents);
        $feeCents = $feeResult['fee_cents'];

        $feeOriginalCents = BigDecimal::of($feeCents)
            ->dividedBy($customerRate, 0, RoundingMode::HALF_UP)
            ->toInt();

        $collectOriginalCents = $originalAmountCents + $feeOriginalCents;

        $marketValueCents = BigDecimal::of($collectOriginalCents)
            ->multipliedBy($marketRate)
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();

        $fxIncomeCents = ($currency === 'USD' || $marginBp === 0)
            ? 0
            : max(0, $marketValueCents - $amountCents - $feeCents);

        $totalDebitCents = $amountCents + $feeCents + $fxIncomeCents;

        return [
            'currency' => $currency,
            'market_rate' => (string) $marketRate,
            'customer_rate' => (string) $customerRate,
            'original_amount_cents' => $originalAmountCents,
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            'fee_snapshot' => $feeResult['snapshot'],
            'fee_original_cents' => $feeOriginalCents,
            'collect_original_cents' => $collectOriginalCents,
            'fx_income_cents' => $fxIncomeCents,
            'total_debit_cents' => $totalDebitCents,
        ];
    }
}
