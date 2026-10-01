<?php

use App\Enums\QuoteMode;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Services\Rates\QuoteService;

beforeEach(function () {
    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
});

function euro(): void
{
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);
}

it('quotes USD with no margin and no fx income', function () {
    // Margin should be ignored for USD.
    $quote = app(QuoteService::class)->quote(QuoteMode::Receive, 'USD', 10000, marginBp: 500);

    expect($quote['amount_cents'])->toBe(10000)
        ->and($quote['fee_cents'])->toBe(500)
        ->and($quote['fee_original_cents'])->toBe(500)
        ->and($quote['collect_original_cents'])->toBe(10500)
        ->and($quote['fx_income_cents'])->toBe(0)
        ->and($quote['total_debit_cents'])->toBe(10500);
});

it('quotes a fixed USD payout in EUR with zero margin — fx income is zero', function () {
    euro();

    // "He must receive $120." Fee $6 on top, collected at 1.08.
    $quote = app(QuoteService::class)->quote(QuoteMode::Receive, 'EUR', 12000, marginBp: 0);

    expect($quote['amount_cents'])->toBe(12000)
        ->and($quote['fee_cents'])->toBe(600)
        ->and($quote['collect_original_cents'])->toBe(11667) // ceil(126.00 / 1.08)
        ->and($quote['fee_original_cents'])->toBe(556)
        ->and($quote['original_amount_cents'])->toBe(11111)
        ->and($quote['fx_income_cents'])->toBe(0)
        ->and($quote['total_debit_cents'])->toBe(12600);
});

it('quotes a fixed USD payout in EUR with a 1% margin — fx income captures the spread', function () {
    euro();

    $quote = app(QuoteService::class)->quote(QuoteMode::Receive, 'EUR', 12000, marginBp: 100);

    expect($quote['customer_rate'])->toBe('1.06920000')
        ->and($quote['amount_cents'])->toBe(12000)
        ->and($quote['fee_cents'])->toBe(600)
        ->and($quote['collect_original_cents'])->toBe(11785) // ceil(126.00 / 1.0692)
        ->and($quote['fx_income_cents'])->toBe(128)
        ->and($quote['total_debit_cents'])->toBe(12728)
        // total_debit must always equal amount + fee + fx_income, and (with a
        // margin) that should match the real USD value of what was collected.
        ->and($quote['total_debit_cents'])->toBe($quote['amount_cents'] + $quote['fee_cents'] + $quote['fx_income_cents']);
});

it('takes the fee out of what the customer hands over in pay mode', function () {
    euro();

    // "Here is €100." At 1.0692 that buys $106.92; the 5% fee comes out of it.
    $quote = app(QuoteService::class)->quote(QuoteMode::Pay, 'EUR', 10000, marginBp: 100);

    expect($quote['collect_original_cents'])->toBe(10000)
        ->and($quote['amount_cents'])->toBe(10183)
        ->and($quote['fee_cents'])->toBe(509)
        ->and($quote['fee_original_cents'])->toBe(476)
        ->and($quote['original_amount_cents'])->toBe(9524)
        ->and($quote['fx_income_cents'])->toBe(108)
        // The whole market value of the cash is accounted for: nothing leaks.
        ->and($quote['total_debit_cents'])->toBe(10800)
        ->and($quote['amount_cents'] + $quote['fee_cents'] + $quote['fx_income_cents'])->toBe(10800);
});

it('takes the fee out of a USD amount too, with no fx income', function () {
    $quote = app(QuoteService::class)->quote(QuoteMode::Pay, 'USD', 10000, marginBp: 500);

    expect($quote['amount_cents'])->toBe(9524)
        ->and($quote['fee_cents'])->toBe(476)
        ->and($quote['collect_original_cents'])->toBe(10000)
        ->and($quote['fx_income_cents'])->toBe(0)
        ->and($quote['total_debit_cents'])->toBe(10000);
});

it('lets the payout leg be typed in another currency, at the market rate', function () {
    euro();

    // "He must receive €100." The payout is still USD — €100 at 1.08 — and
    // the margin applies only to the cash the customer hands over.
    $quote = app(QuoteService::class)->quote(QuoteMode::Receive, 'EUR', 10000, marginBp: 100, payoutCurrency: 'EUR');

    expect($quote['amount_cents'])->toBe(10800)
        ->and($quote['payout_original_cents'])->toBe(10000)
        ->and($quote['payout_currency'])->toBe('EUR')
        ->and($quote['fee_cents'])->toBe(540)
        ->and($quote['collect_original_cents'])->toBe(10607) // ceil(113.40 / 1.0692)
        ->and($quote['total_debit_cents'])
        ->toBe($quote['amount_cents'] + $quote['fee_cents'] + $quote['fx_income_cents']);
});

it('reports the payout leg in the chosen currency in pay mode too', function () {
    euro();

    $quote = app(QuoteService::class)->quote(QuoteMode::Pay, 'EUR', 10000, marginBp: 100, payoutCurrency: 'EUR');

    // $101.83 paid out, shown back to the agent as €94.29 at the market rate.
    expect($quote['amount_cents'])->toBe(10183)
        ->and($quote['payout_original_cents'])->toBe(9429)
        ->and($quote['collect_original_cents'])->toBe(10000);
});

it('keeps collect = original + fee in the customer currency, in both modes', function () {
    euro();

    foreach ([QuoteMode::Pay, QuoteMode::Receive] as $mode) {
        foreach ([1234, 10000, 250000] as $cents) {
            $quote = app(QuoteService::class)->quote($mode, 'EUR', $cents, marginBp: 250);

            expect($quote['original_amount_cents'] + $quote['fee_original_cents'])
                ->toBe($quote['collect_original_cents'])
                ->and($quote['total_debit_cents'])
                ->toBe($quote['amount_cents'] + $quote['fee_cents'] + $quote['fx_income_cents']);
        }
    }
});
