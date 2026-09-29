<?php

use App\Enums\UserType;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);

    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);
});

function sendingAgent(): User
{
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    return $agent;
}

it('requires authentication', function () {
    $this->postJson('/transfers/quote', ['currency' => 'USD', 'amount' => '100'])->assertUnauthorized();
});

it('blocks users without transfers.create', function () {
    $payoutAgent = User::factory()->create(['type' => UserType::PayoutAgent]);
    $payoutAgent->assignRole('payout-agent');

    $this->actingAs($payoutAgent)
        ->postJson('/transfers/quote', ['currency' => 'USD', 'amount' => '100'])
        ->assertForbidden();
});

it('returns a correct USD quote', function () {
    $this->actingAs(sendingAgent())
        ->postJson('/transfers/quote', ['currency' => 'USD', 'amount' => '100'])
        ->assertOk()
        ->assertJson([
            'amount_cents' => 10000,
            'fee_cents' => 500,
            'collect_original_cents' => 10500,
            'fx_income_cents' => 0,
            'total_debit_cents' => 10500,
        ]);
});

it('returns a correct EUR quote using the configured margin', function () {
    Settings::set('currency.margin_bp', 100);

    $this->actingAs(sendingAgent())
        ->postJson('/transfers/quote', ['currency' => 'EUR', 'amount' => '120'])
        ->assertOk()
        ->assertJson([
            'amount_cents' => 12830,
            'fee_cents' => 642,
            'collect_original_cents' => 12600,
            'fx_income_cents' => 136,
            'total_debit_cents' => 13608,
        ]);
});

it('rejects an inactive currency', function () {
    Currency::query()->firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'is_active' => false]);

    $this->actingAs(sendingAgent())
        ->postJson('/transfers/quote', ['currency' => 'CHF', 'amount' => '100'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');
});

it('rejects a zero or negative amount', function () {
    $this->actingAs(sendingAgent())
        ->postJson('/transfers/quote', ['currency' => 'USD', 'amount' => '0'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('returns a friendly 422 when the rate is unavailable', function () {
    Currency::query()->where('code', 'EUR')->update(['manual_rate' => null]);
    Settings::set('currency.max_rate_age_hours', 72);

    $this->actingAs(sendingAgent())
        ->postJson('/transfers/quote', ['currency' => 'EUR', 'amount' => '100'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Exchange rate for EUR is out of date. Ask admin to set a manual rate.');
});
