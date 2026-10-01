<?php

namespace App\Services\Rates;

use App\Enums\QuoteMode;
use App\Services\FeeCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * BUILD.md §7's transfer maths. A transfer has two legs — what the customer
 * hands over and what the receiver is paid — and the agent fills in whichever
 * one the customer named. `$mode` says which:
 *
 * - QuoteMode::Receive — "he must get $100 in Banadir"; the cash to collect
 *                        in currency C is derived and rounded up.
 * - QuoteMode::Pay     — "here is €100, send it"; the fee comes out of the
 *                        €100 and the payout is what is left.
 *
 * Everything stored is USD. `$payoutCurrency` only changes the unit the
 * payout leg is typed and shown in — it converts at the market rate, with no
 * margin, because the spread belongs to the cash leg. The gap between the
 * market rate and the customer rate is company FX income.
 */
class QuoteService
{
    public function __construct(
        private RateService $rates,
        private FeeCalculator $fees,
    ) {}

    /**
     * @param  string  $currency  what the customer pays in
     * @param  int  $inputCents  in $currency for Pay mode, in $payoutCurrency for Receive mode
     * @param  string  $payoutCurrency  the unit the receiver's leg is shown in
     */
    public function quote(
        QuoteMode $mode,
        string $currency,
        int $inputCents,
        int $marginBp,
        string $payoutCurrency = 'USD',
    ): array {
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

        [$amountCents, $feeCents, $feeSnapshot, $collectOriginalCents] = $mode === QuoteMode::Receive
            ? $this->fromPayout($this->toUsd($payoutCurrency, $inputCents), $customerRate)
            : $this->fromCollected($inputCents, $customerRate, $isUsd);

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
            'payout_currency' => $payoutCurrency,
            'market_rate' => (string) $marketRate,
            'customer_rate' => (string) $customerRate,
            'original_amount_cents' => $collectOriginalCents - $feeOriginalCents,
            'amount_cents' => $amountCents,
            // The payout leg in the unit the agent is looking at — what the
            // other field on the form fills itself with.
            'payout_original_cents' => $this->fromUsd($payoutCurrency, $amountCents),
            'fee_cents' => $feeCents,
            'fee_snapshot' => $feeSnapshot,
            'fee_original_cents' => $feeOriginalCents,
            'collect_original_cents' => $collectOriginalCents,
            'fx_income_cents' => $fxIncomeCents,
            'total_debit_cents' => $amountCents + $feeCents + $fxIncomeCents,
        ];
    }

    /** Convert into USD cents at the market rate — no margin, no fee. */
    public function toUsd(string $currency, int $cents): int
    {
        return $currency === 'USD'
            ? $cents
            : BigDecimal::of($cents)
                ->multipliedBy($this->rates->current($currency))
                ->toScale(0, RoundingMode::HALF_UP)
                ->toInt();
    }

    /** The other way: USD cents expressed in $currency, at the market rate. */
    public function fromUsd(string $currency, int $cents): int
    {
        return $currency === 'USD'
            ? $cents
            : BigDecimal::of($cents)
                ->dividedBy($this->rates->current($currency), 0, RoundingMode::HALF_UP)
                ->toInt();
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

        $collectCents = BigDecimal::of($amountCents + $fee['fee_cents'])
            ->dividedBy($customerRate, 0, RoundingMode::CEILING)
            ->toInt();

        return [$amountCents, $fee['fee_cents'], $fee['snapshot'], $collectCents];
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

        return [
            $solved['amount_cents'],
            $solved['fee_cents'],
            $solved['snapshot'],
            $isUsd ? $solved['amount_cents'] + $solved['fee_cents'] : $collectCents,
        ];
    }
}
