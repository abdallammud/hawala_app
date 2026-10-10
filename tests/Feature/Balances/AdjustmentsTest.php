<?php

use App\Enums\LedgerAccountType;
use App\Exceptions\Balances\SelfApprovalException;
use App\Models\AdjustmentRequest;
use App\Models\CompanyAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Reports\ProfitReport;
use App\Reports\ReportFilters;
use App\Services\Balances\AdjustmentService;
use App\Services\Ledger\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ledgerAndCompany(): array
{
    $ledger = app(LedgerService::class);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $companyLedger = $ledger->accountFor($company, LedgerAccountType::Company);
    $adjustments = $ledger->system(LedgerAccountType::Adjustments);

    return [$ledger, $companyLedger, $adjustments];
}

it('blocks users without adjustments.create from the create form', function () {
    $agent = User::factory()->create();
    $agent->assignRole('sending-agent');

    $this->actingAs($agent)->get('/adjustments/create')->assertForbidden();
});

it('offers the adjustments system account on the create form even before it has ever been used', function () {
    $admin = makeAdmin();
    CompanyAccount::create(['name' => 'Mogadishu bank', 'location' => 'somalia', 'is_active' => true]);

    expect(LedgerAccount::query()->where('type', 'adjustments')->exists())->toBeFalse();

    $this->actingAs($admin)->get('/adjustments/create')->assertOk()->assertSee('Adjustments (system)');

    expect(LedgerAccount::query()->where('type', 'adjustments')->exists())->toBeTrue();
});

it('lets an admin submit a pending adjustment with no ledger effect', function () {
    $admin = makeAdmin();
    [, $companyLedger, $adjustments] = ledgerAndCompany();

    $this->actingAs($admin)->post('/adjustments', [
        'debit_ledger_account_id' => $companyLedger->id,
        'credit_ledger_account_id' => $adjustments->id,
        'amount' => '500',
        'reason' => 'Opening balance',
    ])->assertRedirect('/adjustments');

    $request = AdjustmentRequest::query()->firstOrFail();
    expect($request->status->value)->toBe('pending')
        ->and($companyLedger->fresh()->balance_cents)->toBe(0);
});

it('rejects a form where debit and credit are the same account', function () {
    $admin = makeAdmin();
    [, $companyLedger] = ledgerAndCompany();

    $this->actingAs($admin)->post('/adjustments', [
        'debit_ledger_account_id' => $companyLedger->id,
        'credit_ledger_account_id' => $companyLedger->id,
        'amount' => '500',
        'reason' => 'Oops',
    ])->assertSessionHasErrors('debit_ledger_account_id');
});

it('lets a super-admin approve a pending adjustment and posts the ledger entry', function () {
    $admin = makeAdmin();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    [, $companyLedger, $adjustments] = ledgerAndCompany();

    $this->actingAs($admin)->post('/adjustments', [
        'debit_ledger_account_id' => $companyLedger->id,
        'credit_ledger_account_id' => $adjustments->id,
        'amount' => '500',
        'reason' => 'Opening balance',
    ]);
    $request = AdjustmentRequest::query()->firstOrFail();

    $this->actingAs($superAdmin)->post("/adjustments/{$request->id}/approve")->assertRedirect();

    expect($request->fresh()->status->value)->toBe('approved')
        ->and($companyLedger->fresh()->balance_cents)->toBe(50000);
});

it('blocks self-approval of an adjustment', function () {
    $admin = makeAdmin();
    [, $companyLedger, $adjustments] = ledgerAndCompany();

    $service = app(AdjustmentService::class);
    $request = $service->submit($companyLedger, $adjustments, 500_00, 'Opening balance', $admin);

    expect(fn () => $service->approve($request, $admin))->toThrow(SelfApprovalException::class);
});

it('lets a super-admin reject a pending adjustment with a reason', function () {
    $admin = makeAdmin();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    [, $companyLedger, $adjustments] = ledgerAndCompany();

    $this->actingAs($admin)->post('/adjustments', [
        'debit_ledger_account_id' => $companyLedger->id,
        'credit_ledger_account_id' => $adjustments->id,
        'amount' => '500',
        'reason' => 'Opening balance',
    ]);
    $request = AdjustmentRequest::query()->firstOrFail();

    $this->actingAs($superAdmin)->post("/adjustments/{$request->id}/reject", ['reason' => 'Wrong accounts'])
        ->assertRedirect();

    expect($request->fresh()->status->value)->toBe('rejected')
        ->and($request->fresh()->decision_note)->toBe('Wrong accounts')
        ->and($companyLedger->fresh()->balance_cents)->toBe(0);
});

it('offers the opening balances account on the form', function () {
    $admin = makeAdmin();
    CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $this->actingAs($admin)->get('/adjustments/create?opening=1')
        ->assertOk()
        ->assertSee('Opening balances (system)');

    $equity = LedgerAccount::query()->where('type', 'opening_balance')->firstOrFail();
    expect($equity->normal_side->value)->toBe('credit');
});

it('books two opening balances without moving money between accounts or touching profit', function () {
    $admin = makeAdmin();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    [$ledger, $europeLedger] = ledgerAndCompany();
    $mogadishu = CompanyAccount::create(['name' => 'Mogadishu bank', 'location' => 'somalia', 'is_active' => true]);
    $mogadishuLedger = $ledger->accountFor($mogadishu, LedgerAccountType::Company);
    $equity = $ledger->system(LedgerAccountType::OpeningBalance);

    foreach ([[$europeLedger, '12000'], [$mogadishuLedger, '8000.50']] as [$account, $amount]) {
        $this->actingAs($admin)->post('/adjustments', [
            'debit_ledger_account_id' => $account->id,
            'credit_ledger_account_id' => $equity->id,
            'amount' => $amount,
            'reason' => 'Opening balance',
        ]);
    }

    foreach (AdjustmentRequest::query()->get() as $request) {
        $this->actingAs($superAdmin)->post("/adjustments/{$request->id}/approve")->assertRedirect();
    }

    expect($europeLedger->fresh()->balance_cents)->toBe(1_200_000)
        ->and($mogadishuLedger->fresh()->balance_cents)->toBe(800_050)
        ->and($equity->fresh()->balance_cents)->toBe(2_000_050);

    // Not a profit item: the profit report ignores the opening-balance account.
    $report = app(ProfitReport::class)->rows(new ReportFilters(
        from: now()->subDay()->toImmutable(),
        to: now()->addDay()->toImmutable(),
    ), $admin);

    expect($report)->toBeEmpty();
    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});
