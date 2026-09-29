<?php

use App\Models\FeeTier;
use App\Services\FeeCalculator;
use App\Support\Settings;

beforeEach(function () {
    // BUILD.md §5's test tiers: 0–999.99 @ 5% cap $25 · 1000+ @ 2%.
    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
});

it('matches the whole-mode table without a cap issue', function (int $amountCents, int $expectedCents) {
    Settings::set('fees.mode', 'whole');

    expect(app(FeeCalculator::class)->calculate($amountCents)['fee_cents'])->toBe($expectedCents);
})->with([
    'amount 100.00' => [10000, 500],
    'amount 600.00' => [60000, 2500], // capped at $25
    'amount 999.00' => [99900, 2500], // capped at $25
    'amount 1,000.00' => [100000, 2000],
    'amount 1,500.00' => [150000, 3000],
]);

it('matches the marginal-mode table', function (int $amountCents, int $expectedCents) {
    Settings::set('fees.mode', 'marginal');

    expect(app(FeeCalculator::class)->calculate($amountCents)['fee_cents'])->toBe($expectedCents);
})->with([
    'amount 100.00' => [10000, 500],
    'amount 600.00' => [60000, 3000],
    'amount 999.00' => [99900, 4995],
    'amount 1,000.00' => [100000, 5000],
    'amount 1,500.00' => [150000, 6000],
]);

it('shows the uncapped whole-mode fee explicitly for the documented cases', function () {
    Settings::set('fees.mode', 'whole');
    FeeTier::query()->delete();
    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);

    $calculator = app(FeeCalculator::class);

    expect($calculator->calculate(60000)['fee_cents'])->toBe(3000) // $30.00, no cap
        ->and($calculator->calculate(99900)['fee_cents'])->toBe(4995); // $49.95, no cap
});

it('applies the minimum fee floor', function () {
    Settings::set('fees.mode', 'whole');
    Settings::set('fees.min_fee_cents', 1000);

    expect(app(FeeCalculator::class)->calculate(100)['fee_cents'])->toBe(1000); // 5% of $1.00 = $0.05, floored to $10.00 minimum
});

it('rounds half up to the cent', function () {
    Settings::set('fees.mode', 'whole');
    FeeTier::query()->delete();
    FeeTier::create(['from_cents' => 0, 'to_cents' => null, 'rate_bp' => 125, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 0]); // 1.25%

    // 333 * 1.25% = 4.1625 -> rounds to 4 cents (HALF_UP on .1625 rounds down)
    expect(app(FeeCalculator::class)->calculate(333)['fee_cents'])->toBe(4);
});
