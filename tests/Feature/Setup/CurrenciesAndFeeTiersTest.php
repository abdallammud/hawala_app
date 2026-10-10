<?php

use App\Enums\UserType;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true]);
});

it('blocks agents from the currencies screen', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/setup/currencies')->assertForbidden();
});

it('lets an admin set and clear a manual rate', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/currencies/EUR', ['is_active' => 1, 'manual_rate' => '1.09'])
        ->assertRedirect();

    expect((float) Currency::find('EUR')->manual_rate)->toBe(1.09);

    $this->actingAs($admin)->put('/setup/currencies/EUR', ['is_active' => 1, 'manual_rate' => ''])
        ->assertRedirect();

    expect(Currency::find('EUR')->manual_rate)->toBeNull();
});

it('lets an admin toggle a currency active state', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/currencies/EUR', ['is_active' => 0])->assertRedirect();

    expect(Currency::find('EUR')->is_active)->toBeFalse();
});

it('saves a valid fee tier set, replacing the old one', function () {
    $admin = makeAdmin();
    FeeTier::create(['from_cents' => 0, 'to_cents' => null, 'rate_bp' => 100, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 0]);

    $this->actingAs($admin)->put('/setup/fee-tiers', [
        'tiers' => [
            ['from' => '0', 'to' => '1000', 'rate' => '5', 'flat' => '0', 'cap' => '25'],
            ['from' => '1000', 'to' => '', 'rate' => '2', 'flat' => '0', 'cap' => ''],
        ],
    ])->assertRedirect('/setup/fee-tiers');

    $tiers = FeeTier::query()->orderBy('from_cents')->get();

    expect($tiers)->toHaveCount(2)
        ->and($tiers[0]->from_cents)->toBe(0)
        ->and($tiers[0]->to_cents)->toBe(100000)
        ->and($tiers[0]->rate_bp)->toBe(500)
        ->and($tiers[0]->cap_cents)->toBe(2500)
        ->and($tiers[1]->to_cents)->toBeNull()
        ->and($tiers[1]->rate_bp)->toBe(200);
});

it('rejects a tier set that does not start at zero', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/fee-tiers', [
        'tiers' => [['from' => '100', 'to' => '', 'rate' => '5', 'flat' => '0', 'cap' => '']],
    ])->assertSessionHasErrors('tiers');
});

it('rejects a tier set with a gap', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/fee-tiers', [
        'tiers' => [
            ['from' => '0', 'to' => '500', 'rate' => '5', 'flat' => '0', 'cap' => ''],
            ['from' => '1000', 'to' => '', 'rate' => '2', 'flat' => '0', 'cap' => ''],
        ],
    ])->assertSessionHasErrors('tiers');
});

it('rejects a tier set with no open-ended tier', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/fee-tiers', [
        'tiers' => [['from' => '0', 'to' => '1000', 'rate' => '5', 'flat' => '0', 'cap' => '']],
    ])->assertSessionHasErrors('tiers');
});

it('rejects a tier set with two open-ended tiers', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->put('/setup/fee-tiers', [
        'tiers' => [
            ['from' => '0', 'to' => '', 'rate' => '5', 'flat' => '0', 'cap' => ''],
            ['from' => '1000', 'to' => '', 'rate' => '2', 'flat' => '0', 'cap' => ''],
        ],
    ])->assertSessionHasErrors('tiers');
});

it('previews both fee modes without changing the saved setting', function () {
    $admin = makeAdmin();
    Settings::set('fees.mode', 'whole');

    FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 0]);
    FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);

    $this->actingAs($admin)->postJson('/setup/fee-tiers/preview', ['amount' => '1500'])
        ->assertOk()
        ->assertJson(['whole' => '$30.00', 'marginal' => '$60.00']);

    expect(Settings::get('fees.mode'))->toBe('whole');
});

it('lets an admin save settings', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->put('/setup/settings', [
        'fees_mode' => 'marginal',
        'fees_min_fee' => '1',
        'fees_large_transfer' => '5000',
        'fees_refund_on_cancel' => '1',
        'commission_send_type' => 'percent',
        'commission_send_value' => '40',
        'commission_payout_type' => 'percent',
        'commission_payout_value' => '15',
        'currency_margin_bp' => '150',
        'currency_max_rate_age_hours' => '48',
        'payout_claim_minutes' => '45',
        'credit_default_max' => '2000',
        'sms_driver' => 'hormuud',
        'general_company_name' => 'Blue Star',
        'security_idle_timeout_minutes' => 15,
        'general_timezone' => 'Africa/Mogadishu',
        'general_duplicate_warning_minutes' => '15',
    ])->assertRedirect();

    expect(Settings::get('fees.mode'))->toBe('marginal')
        ->and(Settings::get('fees.min_fee_cents'))->toBe(100)
        ->and(Settings::get('currency.margin_bp'))->toBe(150)
        ->and(Settings::get('credit.default_max_cents'))->toBe(200000)
        ->and(Settings::get('general.company_name'))->toBe('Blue Star')
        ->and(Settings::get('payout.allow_negative'))->toBeFalse()
        // SMS stays off unless the checkbox is ticked, whatever the driver says.
        ->and(Settings::get('sms.enabled'))->toBeFalse()
        ->and(Settings::get('sms.driver'))->toBe('hormuud');
});

it('rejects an unknown SMS provider', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->put('/setup/settings', [
        'fees_mode' => 'whole',
        'fees_min_fee' => '0',
        'fees_large_transfer' => '3000',
        'commission_send_type' => 'percent',
        'commission_send_value' => '50',
        'commission_payout_type' => 'percent',
        'commission_payout_value' => '20',
        'currency_margin_bp' => '0',
        'currency_max_rate_age_hours' => '72',
        'payout_claim_minutes' => '30',
        'sms_driver' => 'http',
        'general_company_name' => 'Blue Star',
        'security_idle_timeout_minutes' => 15,
        'general_timezone' => 'Africa/Mogadishu',
        'general_duplicate_warning_minutes' => '10',
    ])->assertSessionHasErrors('sms_driver');
});

it('blocks a plain admin from the settings screen', function () {
    $admin = makeAdmin(); // the seeded admin role excludes setup.settings

    $this->actingAs($admin)->get('/setup/settings')->assertForbidden();
});
