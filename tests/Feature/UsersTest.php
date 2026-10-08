<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReferenceDataSeeder::class);
});

it('blocks users without the permission', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/users')->assertForbidden();
});

it('lets an admin list users', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/users')->assertOk()->assertSee($admin->name);
});

it('lets an admin create a sending agent', function () {
    $admin = makeAdmin();

    $response = $this->actingAs($admin)->post('/users', [
        'name' => 'Ali Hassan',
        'email' => 'ali@example.com',
        'phone' => '+46701234567',
        'password' => 'password123',
        'type' => UserType::SendingAgent->value,
        'branch_id' => Branch::create(['name' => 'London', 'city' => 'London', 'is_payout_point' => false])->id,
        'role' => 'sending-agent',
        'max_credit' => '500',
    ]);

    $response->assertRedirect('/users');

    $user = User::query()->where('email', 'ali@example.com')->firstOrFail();

    expect($user->type)->toBe(UserType::SendingAgent)
        ->and($user->max_credit_cents)->toBe(50000)
        ->and($user->hasRole('sending-agent'))->toBeTrue();
});

it('requires a branch for every user type', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/users', [
        'name' => 'Farah Ali',
        'email' => 'farah@example.com',
        'password' => 'password123',
        'type' => UserType::PayoutAgent->value,
        'role' => 'payout-agent',
    ])->assertSessionHasErrors('branch_id');
});

it('creates a payout agent with a branch and payout accounts', function () {
    $admin = makeAdmin();
    $branch = Branch::query()->first();

    $this->actingAs($admin)->post('/users', [
        'name' => 'Farah Ali',
        'email' => 'farah@example.com',
        'password' => 'password123',
        'type' => UserType::PayoutAgent->value,
        'branch_id' => $branch->id,
        'role' => 'payout-agent',
    ])->assertRedirect('/users');

    $user = User::query()->where('email', 'farah@example.com')->firstOrFail();

    expect($user->branch_id)->toBe($branch->id);

    $accountType = AccountType::query()->first();

    $this->actingAs($admin)->post("/users/{$user->id}/payout-accounts", [
        'account_type_id' => $accountType->id,
        'number' => '61xxxxxxx',
    ])->assertRedirect();

    expect($user->payoutAccounts()->count())->toBe(1);
});

it('blocks a sending agent from managing users', function () {
    $admin = makeAdmin();
    $target = User::factory()->create(['type' => UserType::SendingAgent]);

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get("/users/{$target->id}/edit")->assertForbidden();
});

it('deactivates and reactivates a user', function () {
    $admin = makeAdmin();
    $target = User::factory()->create(['type' => UserType::SendingAgent, 'is_active' => true]);

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();
    expect($target->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->post("/users/{$target->id}/activate")->assertRedirect();
    expect($target->fresh()->is_active)->toBeTrue();
});

it('blocks deactivating a user with a non-zero ledger balance', function () {
    $admin = makeAdmin();
    $target = User::factory()->create(['type' => UserType::SendingAgent, 'is_active' => true]);

    $ledger = app(LedgerService::class);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $companyLedger = $ledger->accountFor($company, LedgerAccountType::Company);
    $send = $ledger->accountFor($target, LedgerAccountType::AgentSend);
    $ledger->post(JournalType::TopupPaid, [[$companyLedger, 100_00], [$send, -100_00]], null, $admin);

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($target->fresh()->is_active)->toBeTrue();

    // Zero the balance back out, then deactivation should succeed.
    $ledger->post(JournalType::TopupPaid, [[$companyLedger, -100_00], [$send, 100_00]], null, $admin);

    $this->actingAs($admin)->post("/users/{$target->id}/deactivate")->assertRedirect();
    expect($target->fresh()->is_active)->toBeFalse();
});

it('gives super-admin access via Gate::before without an explicit permission', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->get('/users')->assertOk();
});
