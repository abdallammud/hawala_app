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

it('adds a round-up to fx income, and ignores one on a USD transfer', function () {
    euro();

    $plain = app(QuoteService::class)->quote(QuoteMode::Receive, 'EUR', 10000, marginBp: 100);
    $rounded = app(QuoteService::class)->quote(QuoteMode::Receive, 'EUR', 10000, marginBp: 100, roundUpCents: 179);

    expect($rounded['collect_original_cents'])->toBe($plain['collect_original_cents'] + 179)
        ->and($rounded['round_up_original_cents'])->toBe(179)
        ->and($rounded['amount_cents'])->toBe($plain['amount_cents'])
        ->and($rounded['fee_cents'])->toBe($plain['fee_cents'])
        // The extra euros are worth their market value in USD, and all of it
        // is company income.
        ->and($rounded['fx_income_cents'])->toBe($plain['fx_income_cents'] + 193)
        ->and($rounded['total_debit_cents'])->toBe($plain['total_debit_cents'] + 193);

    $usd = app(QuoteService::class)->quote(QuoteMode::Receive, 'USD', 10000, marginBp: 0, roundUpCents: 500);

    expect($usd['round_up_original_cents'])->toBe(0)
        ->and($usd['collect_original_cents'])->toBe(10500)
        ->and($usd['fx_income_cents'])->toBe(0);
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
