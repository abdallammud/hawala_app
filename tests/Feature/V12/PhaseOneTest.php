<?php

use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Transfer;
use App\Services\Transfers\TransferService;
use App\Support\NumberSequence;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

function v12Post(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => Branch::query()->first()->id,
        'sender_name' => 'Ali Hassan',
        'sender_phone' => '+46701234567',
        'sender_country' => 'Sweden',
        'sender_city' => 'Stockholm',
        'receiver_name' => 'Farah Omar',
        'receiver_phone' => '+252611234567',
        'receiver_country' => 'Somalia',
        'receiver_city' => 'Mogadishu',
        'currency' => 'USD',
        'payout_currency' => 'USD',
        'mode' => 'receive',
        'payout_amount' => '100',
        'confirm_duplicate' => '1',
    ], $overrides);
}

// --- Transfer numbers ----------------------------------------------------

it('numbers transfers HW-0001, HW-0002, … with four digits at least', function () {
    $agent = H::sendingAgent(fundCents: 1_000_000);
    $service = app(TransferService::class);

    $codes = collect(range(1, 3))->map(fn () => $service->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid())->code);

    expect($codes->all())->toBe(['HW-0001', 'HW-0002', 'HW-0003']);
});

it('keeps counting past four digits', function () {
    DB::table('number_sequences')->insert(['name' => 'transfer', 'last_value' => 9999, 'created_at' => now(), 'updated_at' => now()]);
    $agent = H::sendingAgent(fundCents: 1_000_000);

    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    expect($transfer->code)->toBe('HW-10000');
});

it('leaves no gap when a transfer fails for lack of balance', function () {
    $broke = H::sendingAgent(fundCents: 0);
    $funded = H::sendingAgent(fundCents: 100_000);
    $service = app(TransferService::class);

    expect(fn () => $service->create($broke, $this->branch, H::transferPayload(), (string) Str::uuid()))
        ->toThrow(InsufficientBalanceException::class);

    $ok = $service->create($funded, $this->branch, H::transferPayload(), (string) Str::uuid());

    expect($ok->code)->toBe('HW-0001');
});

it('does not touch codes that already exist', function () {
    $agent = H::sendingAgent(fundCents: 100_000);
    $old = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());
    $old->update(['code' => 'HW-K7Q2M']);

    $new = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(['amount_cents' => 5000]), (string) Str::uuid());

    expect($old->fresh()->code)->toBe('HW-K7Q2M')->and($new->code)->toBe('HW-0002');
});

it('formats numbers with at least four digits', function () {
    expect(NumberSequence::format('HW', 7))->toBe('HW-0007')
        ->and(NumberSequence::format('HW', 12345))->toBe('HW-12345');
});

it('says the number is assigned on send instead of showing one before it exists', function () {
    $agent = H::sendingAgent(fundCents: 100_000);

    $this->actingAs($agent)->get('/transfers/create')->assertOk()
        ->assertSee('Assigned when you send')
        ->assertDontSee('HW-0001');
});

// --- Customer pays is USD and calculated ---------------------------------

it('renders Customer pays read-only and fixed to USD', function () {
    $agent = H::sendingAgent(fundCents: 100_000);

    $html = $this->actingAs($agent)->get('/transfers/create')->assertOk()->getContent();

    expect($html)->toContain('payLocked: true')
        ->toContain("payCurrency: 'USD'")
        ->toMatch('/<input[^>]*name="pay_amount"[^>]*readonly/s')
        ->toMatch('/<input type="hidden" name="currency" value="USD">/')
        ->not->toMatch('/<select[^>]*name="currency"/s')
        // Receiver gets stays editable, with a currency choice.
        ->toMatch('/<select[^>]*name="payout_currency"/s');
});

it('lets the pay currency be unlocked again from Settings', function () {
    Settings::set('transfers.lock_pay_currency', false);
    $agent = H::sendingAgent(fundCents: 100_000);

    $this->actingAs($agent)->get('/transfers/create')->assertOk()
        ->assertSee('payLocked: false', false)
        ->assertSee('<select', false);

    expect(Transfer::payCurrencyLocked())->toBeFalse();
});

it('forces USD and the receiver leg on the server, whatever the browser sends', function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);
    $agent = H::sendingAgent(fundCents: 1_000_000);

    $this->actingAs($agent)->post('/transfers', v12Post([
        'currency' => 'EUR',
        'mode' => 'pay',
        'pay_amount' => '999',
        'payout_amount' => '100',
    ]))->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();
    expect($transfer->original_currency)->toBe('USD')
        ->and($transfer->amount_cents)->toBe(10_000)
        ->and($transfer->collect_original_cents)->toBe($transfer->amount_cents + $transfer->fee_cents);
});

it('forces USD on the quote endpoint too', function () {
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);
    $agent = H::sendingAgent(fundCents: 100_000);

    $this->actingAs($agent)->postJson('/transfers/quote', ['currency' => 'EUR', 'mode' => 'pay', 'amount' => '100', 'payout_currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('amount_cents', 10_000);
});

// --- Wording and logo ----------------------------------------------------

it('says agent balance, not send balance, where the transfer is paid for', function () {
    $agent = H::sendingAgent(fundCents: 100_000);
    $this->actingAs($agent)->post('/transfers', v12Post())->assertRedirect();
    $transfer = Transfer::query()->firstOrFail();

    $this->actingAs($agent)->get('/transfers/create')->assertOk()
        ->assertSee('Taken from your agent balance')
        ->assertDontSee('Taken from your send balance');

    $this->actingAs($agent)->get("/transfers/{$transfer->id}")->assertOk()
        ->assertSee('Taken from agent balance')
        ->assertDontSee('Taken from send balance');
});

it('ships the new logo files at the sizes the app uses', function () {
    $sizes = [
        'images/logo-mark.png' => 800,
        'images/logo-receipt.png' => 176,
        'images/apple-touch-icon.png' => 180,
        'images/favicon-32x32.png' => 32,
        'images/favicon-16x16.png' => 16,
    ];

    foreach ($sizes as $file => $size) {
        [$w, $h] = getimagesize(public_path($file));
        expect([$file, $w, $h])->toBe([$file, $size, $size]);
    }

    expect(public_path('images/logo-wide.png'))->not->toBeFile()
        ->and(public_path('favicon.ico'))->toBeFile();
});
