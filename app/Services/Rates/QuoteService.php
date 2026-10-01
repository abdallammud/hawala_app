<?php

namespace App\Services\Rates;

use App\Enums\QuoteMode;
use App\Services\FeeCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * BUILD.md §7's transfer maths, in both directions.
 *
 * - QuoteMode::Pay     — the customer hands over a fixed amount in currency C
 *                        and the fee comes out of it ("here is €100, send it").
 * - QuoteMode::Receive — the receiver must be paid a fixed amount in USD and
 *                        the amount to collect in C is derived, rounded up
 *                        ("how much do I pay so he gets $100 in Banadir?").
 *
 * The gap between the market rate and the customer rate — plus any round-up
 * the agent adds in Receive mode — is company FX income.
 */
class QuoteService
{
    public function __construct(
        private RateService $rates,
        private FeeCalculator $fees,
    ) {}

    /**
     * @param  int  $inputCents  in C for Pay mode, in USD for Receive mode
     * @param  int  $roundUpCents  extra collected in C, Receive mode only
     */
    public function quote(QuoteMode $mode, string $currency, int $inputCents, int $marginBp, int $roundUpCents = 0): array
    {
        $isUsd = $currency === 'USD';
        $marketRate = $this->rates->current($currency);

        // For USD, rate = 1 and margin never applies (BUILD.md §7). Kept at
        // the 8 decimals the column stores, so the quote and the saved
        // transfer can never disagree.
        $customerRate = $isUsd
            ? $marketRate
            : $marketRate->multipliedBy(
                BigDecimal::one()->minus(
                    BigDecimal::of(min($marginBp, 9999))->dividedBy(10000, 8, RoundingMode::HALF_UP)
                )
            )->toScale(8, RoundingMode::HALF_UP);

        // A round-up is an FX-leg feature: there is no spread to round on a
        // USD-to-USD transfer, so it is ignored rather than silently charged.
        $roundUpCents = $isUsd ? 0 : max(0, $roundUpCents);

        [$amountCents, $feeCents, $feeSnapshot, $collectMinCents] = $mode === QuoteMode::Receive
            ? $this->fromPayout($inputCents, $customerRate)
            : $this->fromCollected($inputCents, $customerRate, $isUsd);

        $collectOriginalCents = $collectMinCents + $roundUpCents;

        $feeOriginalCents = $isUsd
            ? $feeCents
            : BigDecimal::of($feeCents)->dividedBy($customerRate, 0, RoundingMode::HALF_UP)->toInt();

        $marketValueCents = $isUsd
            ? $collectOriginalCents
            : BigDecimal::of($collectOriginalCents)->multipliedBy($marketRate)->toScale(0, RoundingMode::HALF_UP)->toInt();

        // The USD value of the cash collected, less what is actually sent, is
        // what the company keeps. USD never has an FX leg, so never income.
        $fxIncomeCents = $isUsd ? 0 : max(0, $marketValueCents - $amountCents - $feeCents);

        return [
            'quote_mode' => $mode->value,
            'currency' => $currency,
            'market_rate' => (string) $marketRate,
            'customer_rate' => (string) $customerRate,
            'original_amount_cents' => $collectOriginalCents - $feeOriginalCents,
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            'fee_snapshot' => $feeSnapshot,
            'fee_original_cents' => $feeOriginalCents,
            'collect_min_original_cents' => $collectMinCents,
            'round_up_original_cents' => $roundUpCents,
            'collect_original_cents' => $collectOriginalCents,
            'fx_income_cents' => $fxIncomeCents,
            'total_debit_cents' => $amountCents + $feeCents + $fxIncomeCents,
        ];
    }

    /**
     * Receive mode: the payout is given, the collected amount is derived and
     * rounded UP to the cent so the company is never a cent short.
     *
     * @return array{0:int,1:int,2:array,3:int}
     */
    private function fromPayout(int $amountCents, BigDecimal $customerRate): array
    {
        $fee = $this->fees->calculate($amountCents);

        $collectMinCents = BigDecimal::of($amountCents + $fee['fee_cents'])
            ->dividedBy($customerRate, 0, RoundingMode::CEILING)
            ->toInt();

        return [$amountCents, $fee['fee_cents'], $fee['snapshot'], $collectMinCents];
    }

    /**
     * Pay mode: the collected amount is given, the fee comes out of it and
     * the payout is what is left. For USD the collected amount is trimmed to
     * the exact total so no stray cent sits outside the ledger.
     *
     * @return array{0:int,1:int,2:array,3:int}
     */
    private function fromCollected(int $collectCents, BigDecimal $customerRate, bool $isUsd): array
    {
        $budgetCents = $isUsd
            ? $collectCents
            : BigDecimal::of($collectCents)->multipliedBy($customerRate)->toScale(0, RoundingMode::HALF_UP)->toInt();

        $solved = $this->fees->inverse($budgetCents);

        $collectMinCents = $isUsd
            ? $solved['amount_cents'] + $solved['fee_cents']
            : $collectCents;

        return [$solved['amount_cents'], $solved['fee_cents'], $solved['snapshot'], $collectMinCents];
    }
}
