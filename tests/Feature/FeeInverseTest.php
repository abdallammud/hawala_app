<?php

use App\Exceptions\Transfers\AmountTooSmallException;
use App\Models\FeeTier;
use App\Services\FeeCalculator;
use App\Support\Settings;

/**
 * The other direction of BUILD.md §5: the customer hands over a fixed total
 * and the fee comes out of it. `inverse()` must find the largest amount whose
 * own fee still fits — never one cent more.
 */
beforeEach(function () {
    // Same tiers as FeeCalculatorTest: 0–999.99 @ 5% cap $25 · 1000+ @ 2%.
    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
});

it('splits a budget into the biggest payout its own fee allows', function (string $mode, int $budget) {
    Settings::set('fees.mode', $mode);

    $fees = app(FeeCalculator::class);
    $solved = $fees->inverse($budget);

    // Exactly what calculate() would charge for that payout…
    expect($solved['fee_cents'])->toBe($fees->calculate($solved['amount_cents'])['fee_cents'])
        // …it fits inside the budget…
        ->and($solved['amount_cents'] + $solved['fee_cents'])->toBeLessThanOrEqual($budget)
        // …and nothing is left over but rounding, never a whole cent of payout.
        ->and($budget - $solved['amount_cents'] - $solved['fee_cents'])->toBeLessThan(2);

    // One cent more would not fit — this is the maximum.
    $next = $solved['amount_cents'] + 1;
    expect($next + $fees->calculate($next)['fee_cents'])->toBeGreaterThan($budget);
})->with([
    'whole · $10' => ['whole', 1000],
    'whole · $100' => ['whole', 10000],
    'whole · $525.17' => ['whole', 52517],
    'whole · over the cap' => ['whole', 80000],
    'whole · across the tier line' => ['whole', 102500],
    'whole · $5,000' => ['whole', 500000],
    'marginal · $100' => ['marginal', 10000],
    'marginal · $999.99' => ['marginal', 99999],
    'marginal · across the tier line' => ['marginal', 105000],
    'marginal · $5,000' => ['marginal', 500000],
]);

it('round-trips: a payout plus its fee comes back as the same payout', function () {
    $fees = app(FeeCalculator::class);

    foreach ([100, 2599, 10000, 100000, 250000] as $amount) {
        $budget = $amount + $fees->calculate($amount)['fee_cents'];

        expect($fees->inverse($budget)['amount_cents'])->toBe($amount);
    }
});

it('never pays the receiver less than the round trip would', function () {
    // These tiers cap tier 1's fee at $25 while tier 2 charges a flat 2%, so
    // the fee is not monotonic across the tier line: $999.99 costs $25 to
    // send, $1,004.89 costs $20.10. For the same cash the customer gets the
    // larger payout — the rule is "the biggest payout the budget covers",
    // never a smaller one with a bigger fee.
    $fees = app(FeeCalculator::class);

    $budget = 99999 + $fees->calculate(99999)['fee_cents'];
    $solved = $fees->inverse($budget);

    expect($solved['amount_cents'])->toBe(100489)
        ->and($solved['fee_cents'])->toBe(2010)
        ->and($solved['amount_cents'] + $solved['fee_cents'])->toBe($budget);
});

it('respects a minimum fee', function () {
    Settings::set('fees.min_fee_cents', 300);

    $solved = app(FeeCalculator::class)->inverse(1000);

    // 5% of the payout is under $3, so the floor applies and takes $3 flat.
    expect($solved['fee_cents'])->toBe(300)
        ->and($solved['amount_cents'])->toBe(700);
});

it('respects a flat fee on top of the rate', function () {
    FeeTier::query()->delete();
    FeeTier::create(['from_cents' => 0, 'to_cents' => null, 'rate_bp' => 500, 'flat_cents' => 150, 'cap_cents' => null, 'sort' => 0]);

    $fees = app(FeeCalculator::class);
    $solved = $fees->inverse(10000);

    expect($solved['amount_cents'])->toBe(9381)   // 93.81 + 4.69 + 1.50 = 100.00
        ->and($solved['fee_cents'])->toBe(619)
        ->and($solved['amount_cents'] + $solved['fee_cents'])->toBe(10000);
});

it('refuses a budget that cannot cover the fee', function () {
    Settings::set('fees.min_fee_cents', 500);

    expect(fn () => app(FeeCalculator::class)->inverse(400))
        ->toThrow(AmountTooSmallException::class);
});

it('freezes the same snapshot the forward calculation would', function () {
    $fees = app(FeeCalculator::class);
    $solved = $fees->inverse(10000);

    expect($solved['snapshot'])->toBe($fees->calculate($solved['amount_cents'])['snapshot']);
});
