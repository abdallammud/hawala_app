<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function fundCommission(User $agent, int $cents): void
{
    $ledger = app(LedgerService::class);
    $commission = $ledger->accountFor($agent, LedgerAccountType::AgentCommission);
    $ledger->post(
        JournalType::ManualAdjustment,
        [[$ledger->system(LedgerAccountType::CommissionExpense), $cents], [$commission, -$cents]],
        null,
        $agent
    );
}

it('moves commission instantly by default', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    fundCommission($agent, 100_00);

    $this->actingAs($agent)->post('/commission/move', ['amount' => '50'])->assertRedirect();

    $ledger = app(LedgerService::class);
    $send = $ledger->accountFor($agent, LedgerAccountType::AgentSend);
    $commission = $ledger->accountFor($agent, LedgerAccountType::AgentCommission);

    expect($send->fresh()->balance_cents)->toBe(5000)
        ->and($commission->fresh()->balance_cents)->toBe(5000);
});

it('requires approval to move commission when the setting is on', function () {
    Settings::set('commission.move_requires_approval', true);

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    fundCommission($agent, 100_00);

    $this->actingAs($agent)->post('/commission/move', ['amount' => '50'])->assertRedirect();

    $request = BalanceRequest::query()->where('user_id', $agent->id)->where('kind', 'commission_move')->firstOrFail();
    expect($request->status->value)->toBe('pending');

    $commission = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentCommission);
    expect($commission->fresh()->balance_cents)->toBe(10000); // unchanged
});

it('always requires approval for a commission withdrawal', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    fundCommission($agent, 100_00);

    $this->actingAs($agent)->post('/commission/withdraw', [
        'amount' => '50',
        'company_account_id' => $company->id,
    ])->assertRedirect();

    $request = BalanceRequest::query()->where('user_id', $agent->id)->where('kind', 'commission_withdraw')->firstOrFail();
    expect($request->status->value)->toBe('pending');
});

it('blocks a payout agent from the commission move endpoint they lack permission for', function () {
    $agent = User::factory()->create(['type' => UserType::PayoutAgent]);
    $agent->assignRole('payout-agent');

    $this->actingAs($agent)->post('/commission/move', ['amount' => '50'])->assertForbidden();
});
