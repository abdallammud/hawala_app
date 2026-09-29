<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\CompanyAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks users without users.view from seeing a statement', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get("/users/{$agent->id}/statement")->assertForbidden();
});

it('shows an empty state without creating a ledger account as a side effect', function () {
    $admin = makeAdmin();
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);

    $this->actingAs($admin)->get("/users/{$agent->id}/statement")
        ->assertOk()
        ->assertSee('No activity yet');

    expect(LedgerAccount::query()->where('owner_id', $agent->id)->count())->toBe(0);
});

it('shows the running balance for an existing account', function () {
    $admin = makeAdmin();
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $ledger = app(LedgerService::class);
    $companyLedger = $ledger->accountFor($company, LedgerAccountType::Company);
    $send = $ledger->accountFor($agent, LedgerAccountType::AgentSend);
    $ledger->post(JournalType::TopupPaid, [[$companyLedger, 250_00], [$send, -250_00]], null, $admin);

    $this->actingAs($admin)->get("/users/{$agent->id}/statement?type=agent_send")
        ->assertOk()
        ->assertSee('$250.00');
});
