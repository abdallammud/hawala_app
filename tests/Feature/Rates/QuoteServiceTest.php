<?php

use App\Models\Currency;
use App\Models\FeeTier;
use App\Services\Rates\QuoteService;

beforeEach(function () {
    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
});

it('quotes USD with no margin and no fx income', function () {
    $quote = app(QuoteService::class)->quote('USD', 10000, marginBp: 500); // margin should be ignored for USD

    expect($quote['amount_cents'])->toBe(10000)
        ->and($quote['fee_cents'])->toBe(500)
        ->and($quote['fee_original_cents'])->toBe(500)
        ->and($quote['collect_original_cents'])->toBe(10500)
        ->and($quote['fx_income_cents'])->toBe(0)
        ->and($quote['total_debit_cents'])->toBe(10500);
});

it('quotes EUR with zero margin — fx income is zero', function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);

    $quote = app(QuoteService::class)->quote('EUR', 12000, marginBp: 0);

    expect($quote['amount_cents'])->toBe(12960)
        ->and($quote['fee_cents'])->toBe(648)
        ->and($quote['fee_original_cents'])->toBe(600)
        ->and($quote['collect_original_cents'])->toBe(12600)
        ->and($quote['fx_income_cents'])->toBe(0)
        ->and($quote['total_debit_cents'])->toBe(13608);
});

it('quotes EUR with a 1% margin — fx income captures the spread', function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);

    $quote = app(QuoteService::class)->quote('EUR', 12000, marginBp: 100);

    expect($quote['amount_cents'])->toBe(12830)
        ->and($quote['fee_cents'])->toBe(642)
        ->and($quote['fee_original_cents'])->toBe(600)
        ->and($quote['collect_original_cents'])->toBe(12600)
        ->and($quote['fx_income_cents'])->toBe(136)
        ->and($quote['total_debit_cents'])->toBe(13608)
        // total_debit must always equal amount + fee + fx_income, and (with a
        // margin) that should match the real USD value of what was collected.
        ->and($quote['total_debit_cents'])->toBe($quote['amount_cents'] + $quote['fee_cents'] + $quote['fx_income_cents']);
});
