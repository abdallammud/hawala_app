<?php

use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReferenceDataSeeder::class);
});

it('blocks agents from the setup hub', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/setup')->assertForbidden();
});

it('lets an admin manage branches', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/setup/branches')->assertOk()->assertSee('Mogadishu');

    $this->actingAs($admin)->post('/setup/branches', [
        'name' => 'Bosaso',
        'city' => 'Bosaso',
    ])->assertRedirect('/setup/branches');

    expect(Branch::query()->where('name', 'Bosaso')->exists())->toBeTrue();
});

it('creates and edits a branch without a city', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/setup/branches', ['name' => 'Garowe', 'city' => ''])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/setup/branches');

    $branch = Branch::query()->where('name', 'Garowe')->firstOrFail();
    expect($branch->city)->toBeNull();

    $this->actingAs($admin)->get('/setup/branches')->assertOk()->assertSee('Garowe');

    $this->actingAs($admin)->put("/setup/branches/{$branch->id}", ['name' => 'Garowe', 'is_payout_point' => 1])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->post('/setup/branches', ['name' => ''])->assertSessionHasErrors('name');
});

it('rejects a duplicate branch name', function () {
    $admin = makeAdmin();
    $existing = Branch::query()->first();

    $this->actingAs($admin)->post('/setup/branches', [
        'name' => $existing->name,
        'city' => 'Somewhere',
    ])->assertSessionHasErrors('name');
});

it('blocks deactivating a branch with active payout agents', function () {
    $admin = makeAdmin();
    $branch = Branch::query()->first();

    $agent = User::factory()->create(['type' => UserType::PayoutAgent, 'branch_id' => $branch->id, 'is_active' => true]);
    $agent->assignRole('payout-agent');

    $this->actingAs($admin)->post("/setup/branches/{$branch->id}/toggle")
        ->assertRedirect()
        ->assertSessionHasErrors('branch');

    expect($branch->fresh()->is_active)->toBeTrue();
});

it('deactivates and reactivates a branch with no active agents', function () {
    $admin = makeAdmin();
    $branch = Branch::create(['name' => 'Empty Branch', 'city' => 'Nowhere', 'is_active' => true]);

    $this->actingAs($admin)->post("/setup/branches/{$branch->id}/toggle")->assertRedirect();
    expect($branch->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->post("/setup/branches/{$branch->id}/toggle")->assertRedirect();
    expect($branch->fresh()->is_active)->toBeTrue();
});

it('lets an admin manage account types', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/setup/account-types')->assertOk()->assertSee('EVC Plus');

    $this->actingAs($admin)->post('/setup/account-types', [
        'name' => 'Golis Telecom',
        'sort' => 10,
    ])->assertRedirect('/setup/account-types');

    expect(AccountType::query()->where('name', 'Golis Telecom')->exists())->toBeTrue();
});

it('blocks deactivating an account type used by an active payout account', function () {
    $admin = makeAdmin();
    $accountType = AccountType::query()->first();

    $agent = User::factory()->create(['type' => UserType::PayoutAgent, 'is_active' => true]);
    $agent->payoutAccounts()->create(['account_type_id' => $accountType->id, 'number' => '123', 'is_active' => true]);

    $this->actingAs($admin)->post("/setup/account-types/{$accountType->id}/toggle")
        ->assertRedirect()
        ->assertSessionHasErrors('accountType');

    expect($accountType->fresh()->is_active)->toBeTrue();
});
