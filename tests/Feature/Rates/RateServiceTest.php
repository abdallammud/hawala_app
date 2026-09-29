<?php

use App\Exceptions\Rates\RateUnavailableException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Rates\RateService;
use App\Support\Settings;
use Brick\Math\BigDecimal;

beforeEach(function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true]);
});

it('always returns exactly 1 for USD', function () {
    expect(app(RateService::class)->current('USD')->isEqualTo(BigDecimal::one()))->toBeTrue();
});

it('uses the manual override when set, ignoring fetched rates', function () {
    Currency::query()->where('code', 'EUR')->update(['manual_rate' => '1.10000000']);
    ExchangeRate::create(['currency' => 'EUR', 'rate_to_usd' => '1.05000000', 'source' => 'test', 'fetched_at' => now()]);

    $rate = app(RateService::class)->current('EUR');

    expect($rate->isEqualTo(BigDecimal::of('1.10000000')))->toBeTrue();
});

it('uses the latest fetched rate when no manual override and it is fresh', function () {
    ExchangeRate::create(['currency' => 'EUR', 'rate_to_usd' => '1.05000000', 'source' => 'test', 'fetched_at' => now()->subHours(2)]);
    ExchangeRate::create(['currency' => 'EUR', 'rate_to_usd' => '1.08000000', 'source' => 'test', 'fetched_at' => now()->subMinutes(10)]);

    $rate = app(RateService::class)->current('EUR');

    expect($rate->isEqualTo(BigDecimal::of('1.08000000')))->toBeTrue();
});

it('throws when the latest rate is older than max_rate_age_hours', function () {
    Settings::set('currency.max_rate_age_hours', 72);
    ExchangeRate::create(['currency' => 'EUR', 'rate_to_usd' => '1.05000000', 'source' => 'test', 'fetched_at' => now()->subHours(100)]);

    expect(fn () => app(RateService::class)->current('EUR'))->toThrow(RateUnavailableException::class);
});

it('throws when there is no rate at all', function () {
    expect(fn () => app(RateService::class)->current('EUR'))->toThrow(RateUnavailableException::class);
});
