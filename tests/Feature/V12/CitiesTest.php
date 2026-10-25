<?php

use App\Models\City;
use App\Models\Transfer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

function citiesPost(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => test()->branch->id,
        'sender_name' => 'Ali Hassan', 'sender_phone' => '+46701234567',
        'sender_country' => 'Sweden', 'sender_city' => 'Stockholm',
        'receiver_name' => 'Farah Omar', 'receiver_phone' => '+252611234567',
        'receiver_country' => 'Somalia', 'receiver_city' => 'Mogadishu',
        'currency' => 'USD', 'payout_currency' => 'USD', 'mode' => 'receive', 'payout_amount' => '50',
        'confirm_duplicate' => '1',
    ], $overrides);
}

it('has a starting list of cities for every country on the transfer form', function () {
    $withCities = City::query()->distinct()->pluck('country')->all();

    expect(array_diff(config('hawala.countries'), $withCities))->toBe([]);
});

it('rejects a city that belongs to another country', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)->post('/transfers', citiesPost(['sender_city' => 'Mogadishu']))
        ->assertSessionHasErrors('sender_city');

    $this->actingAs($agent)->post('/transfers', citiesPost(['receiver_city' => 'Stockholm']))
        ->assertSessionHasErrors('receiver_city');

    expect(Transfer::count())->toBe(0);
});

it('rejects a city nobody has set up, even for a valid country', function () {
    $this->actingAs(H::sendingAgent(fundCents: 100000))
        ->post('/transfers', citiesPost(['receiver_city' => 'Made Up Town']))
        ->assertSessionHasErrors('receiver_city');
});

it('rejects an inactive city', function () {
    City::query()->where(['country' => 'Somalia', 'name' => 'Hargeisa'])->update(['is_active' => false]);

    $this->actingAs(H::sendingAgent(fundCents: 100000))
        ->post('/transfers', citiesPost(['receiver_city' => 'Hargeisa']))
        ->assertSessionHasErrors('receiver_city');
});

it('accepts a city that belongs to the chosen country', function () {
    $this->actingAs(H::sendingAgent(fundCents: 100000))
        ->post('/transfers', citiesPost(['sender_city' => 'Malmö', 'receiver_city' => 'Hargeisa']))
        ->assertSessionDoesntHaveErrors();

    expect(Transfer::sole())->sender_city->toBe('Malmö')->receiver_city->toBe('Hargeisa');
});

it('gives the transfer form each country\'s cities, and only those', function () {
    $this->actingAs(H::sendingAgent())->get('/transfers/create')->assertOk()
        ->assertSee('name="sender_city"', false)->assertSee('name="receiver_city"', false)
        ->assertSee("parentName: 'sender_country'", false)->assertSee("parentName: 'receiver_country'", false)
        ->assertSee('Stockholm')->assertSee('Hargeisa');

    $byCountry = City::namesByCountry();

    expect($byCountry['Somalia'])->toContain('Mogadishu', 'Hargeisa')->not->toContain('Stockholm')
        ->and($byCountry['Sweden'])->toContain('Stockholm')->not->toContain('Mogadishu');
});

it('lets an admin add, rename and deactivate a city', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/setup/cities?country=Somalia&q=Mogadishu')->assertOk()->assertSee('Mogadishu');
    $this->actingAs($admin)->get('/setup')->assertSee(route('setup.cities.index'));

    $this->actingAs($admin)->post('/setup/cities', ['country' => 'Somalia', 'name' => 'Xudur'])
        ->assertSessionDoesntHaveErrors();
    $city = City::query()->where('name', 'Xudur')->firstOrFail();
    expect($city->country)->toBe('Somalia')->and($city->is_active)->toBeTrue();

    $this->actingAs($admin)->put("/setup/cities/{$city->id}", ['country' => 'Somalia', 'name' => 'Xudur Town'])
        ->assertSessionDoesntHaveErrors();
    expect($city->fresh()->name)->toBe('Xudur Town');

    $this->actingAs($admin)->post("/setup/cities/{$city->id}/toggle");
    expect($city->fresh()->is_active)->toBeFalse()
        ->and(City::namesByCountry()['Somalia'])->not->toContain('Xudur Town');
});

it('does not allow a duplicate city in the same country, but allows the same name elsewhere', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/setup/cities', ['country' => 'Somalia', 'name' => 'Mogadishu'])
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)->post('/setup/cities', ['country' => 'Kenya', 'name' => 'Mogadishu'])
        ->assertSessionDoesntHaveErrors();
});

it('keeps agents out of the cities setup', function () {
    $agent = H::sendingAgent();

    $this->actingAs($agent)->get('/setup/cities')->assertForbidden();
    $this->actingAs($agent)->post('/setup/cities', ['country' => 'Somalia', 'name' => 'Xudur'])->assertForbidden();
    expect(City::query()->where('name', 'Xudur')->exists())->toBeFalse();
});
