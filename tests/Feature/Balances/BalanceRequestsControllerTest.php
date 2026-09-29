<?php

use App\Enums\BalanceRequestKind;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Services\Balances\BalanceRequestService;
use App\Services\Ledger\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks guests and unauthorized roles', function () {
    $this->get('/balance-requests')->assertRedirect('/login');

    $bystander = User::factory()->create(['type' => UserType::Staff]);
    $this->actingAs($bystander)->get('/balance-requests')->assertForbidden();
});

it('lets a sending agent submit a topup_paid request that goes pending', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $this->actingAs($agent)->post('/balance-requests', [
        'kind' => 'topup_paid',
        'amount' => '500',
        'company_account_id' => $company->id,
    ])->assertRedirect('/balance-requests');

    $request = BalanceRequest::query()->where('user_id', $agent->id)->firstOrFail();
    expect($request->status->value)->toBe('pending')
        ->and($request->amount_cents)->toBe(50000);
});

it('rejects a kind the requester type is not allowed to submit', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->post('/balance-requests', [
        'kind' => 'payout_funding', // sending agents can't request this
        'amount' => '500',
    ])->assertSessionHasErrors('kind');
});

it('lets an admin record and instantly approve a topup on behalf of an agent', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $this->actingAs($admin)->post('/balance-requests', [
        'kind' => 'topup_paid',
        'user_id' => $agent->id,
        'amount' => '500',
        'company_account_id' => $company->id,
    ])->assertRedirect('/balance-requests');

    $request = BalanceRequest::query()->where('user_id', $agent->id)->firstOrFail();
    expect($request->status->value)->toBe('approved');

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);
    expect($send->fresh()->balance_cents)->toBe(50000);
});

it('blocks non-approvers from the inbox', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/balance-requests/inbox')->assertForbidden();
});

it('lets an admin approve a pending request from the inbox', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $this->actingAs($agent)->post('/balance-requests', [
        'kind' => 'topup_paid',
        'amount' => '500',
        'company_account_id' => $company->id,
    ]);
    $request = BalanceRequest::query()->where('user_id', $agent->id)->firstOrFail();

    $this->actingAs($admin)->get('/balance-requests/inbox')->assertOk()->assertSee($agent->name);

    $this->actingAs($admin)->post("/balance-requests/{$request->id}/approve")->assertRedirect();

    expect($request->fresh()->status->value)->toBe('approved');
});

it('blocks the requester from approving their own request via the inbox route', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    // The admin requests on behalf of the agent but as a pending (not
    // instant) request, so requested_by = decided_by would be the admin.
    $request = app(BalanceRequestService::class)->submit(BalanceRequestKind::TopupPaid, $agent, 100_00, $admin, $company);

    $this->actingAs($admin)->post("/balance-requests/{$request->id}/approve")
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($request->fresh()->status->value)->toBe('pending');
});

it('lets an admin reject a pending request with a reason', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $this->actingAs($agent)->post('/balance-requests', [
        'kind' => 'topup_paid',
        'amount' => '500',
        'company_account_id' => $company->id,
    ]);
    $request = BalanceRequest::query()->where('user_id', $agent->id)->firstOrFail();

    $this->actingAs($admin)->post("/balance-requests/{$request->id}/reject", ['reason' => 'No proof'])
        ->assertRedirect();

    expect($request->fresh()->status->value)->toBe('rejected')
        ->and($request->fresh()->note)->toBe('No proof');
});
