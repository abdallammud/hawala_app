<?php

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\RateFetchFailed;
use App\Services\Rates\FrankfurterProvider;
use App\Services\Rates\OpenErApiProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('inverts Frankfurter\'s units-per-USD rates into rate_to_usd', function () {
    Http::fake([
        'api.frankfurter.dev/*' => Http::response([
            'base' => 'USD',
            'rates' => ['EUR' => 0.92, 'GBP' => 0.80],
        ]),
    ]);

    $rates = app(FrankfurterProvider::class)->fetch(['EUR', 'GBP']);

    // 1 / 0.92 = 1.08695652...
    expect((string) $rates['EUR'])->toStartWith('1.08695652')
        ->and((string) $rates['GBP'])->toBe('1.25000000');
});

it('throws on a Frankfurter failure response', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response(null, 500)]);

    expect(fn () => app(FrankfurterProvider::class)->fetch(['EUR']))->toThrow(RuntimeException::class);
});

it('inverts open-er-api\'s rates the same way', function () {
    Http::fake([
        'open.er-api.com/*' => Http::response([
            'result' => 'success',
            'rates' => ['EUR' => 0.92, 'GBP' => 0.80],
        ]),
    ]);

    $rates = app(OpenErApiProvider::class)->fetch(['EUR', 'GBP']);

    expect((string) $rates['GBP'])->toBe('1.25000000');
});

it('fetch-rates falls back to the second provider when the first fails', function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true]);

    Http::fake([
        'api.frankfurter.dev/*' => Http::response(null, 500),
        'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['EUR' => 0.92]]),
    ]);

    Artisan::call('hawala:fetch-rates');

    $stored = ExchangeRate::query()->where('currency', 'EUR')->latest('fetched_at')->first();

    expect($stored)->not->toBeNull()
        ->and($stored->source)->toBe('open-er-api');
});

it('fetch-rates fails and notifies super admins once when both providers fail', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    Http::fake([
        'api.frankfurter.dev/*' => Http::response(null, 500),
        'open.er-api.com/*' => Http::response(null, 500),
    ]);

    $exitCode = Artisan::call('hawala:fetch-rates');

    expect($exitCode)->toBe(1);
    Notification::assertSentTo($superAdmin, RateFetchFailed::class);
});
