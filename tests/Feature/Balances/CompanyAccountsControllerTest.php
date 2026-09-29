<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('blocks agents from company accounts', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/company-accounts')->assertForbidden();
});

it('lets an admin create a company account with a live ledger account', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/company-accounts', [
        'name' => 'Europe office',
        'location' => 'europe',
    ])->assertRedirect('/company-accounts');

    $account = CompanyAccount::query()->where('name', 'Europe office')->firstOrFail();
    expect($account->ledger_account_id)->not->toBeNull()
        ->and($account->ledgerAccount->balance_cents)->toBe(0);
});

it('can reach the create form before the show route swallows it', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/company-accounts/create')->assertOk()->assertSee('New company account');
});

it('shows a statement with a running balance', function () {
    $admin = makeAdmin();
    $account = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $ledger = app(LedgerService::class);
    $ledgerAccount = $ledger->accountFor($account, LedgerAccountType::Company);
    $account->update(['ledger_account_id' => $ledgerAccount->id]);

    $ledger->post(
        JournalType::ManualAdjustment,
        [[$ledgerAccount, 500_00], [$ledger->system(LedgerAccountType::Adjustments), -500_00]],
        null,
        $admin
    );

    $this->actingAs($admin)->get("/company-accounts/{$account->id}")
        ->assertOk()
        ->assertSee('$500.00');
});

it('moves money between two company accounts with charges', function () {
    $admin = makeAdmin();
    $from = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $to = CompanyAccount::create(['name' => 'Mogadishu bank', 'location' => 'somalia', 'is_active' => true]);

    $ledger = app(LedgerService::class);
    $fromLedger = $ledger->accountFor($from, LedgerAccountType::Company);
    $ledger->post(
        JournalType::ManualAdjustment,
        [[$fromLedger, 1000_00], [$ledger->system(LedgerAccountType::Adjustments), -1000_00]],
        null,
        $admin
    );

    $this->actingAs($admin)->post('/company-accounts-transfer', [
        'from_company_account_id' => $from->id,
        'to_company_account_id' => $to->id,
        'sent' => '100',
        'received' => '95',
        'transferred_at' => now()->toDateString(),
    ])->assertRedirect('/company-accounts');

    $toLedger = $ledger->accountFor($to, LedgerAccountType::Company);
    $bankCharges = $ledger->system(LedgerAccountType::BankCharges);

    expect($fromLedger->fresh()->balance_cents)->toBe(90000)
        ->and($toLedger->fresh()->balance_cents)->toBe(9500)
        ->and($bankCharges->fresh()->balance_cents)->toBe(500);
});
