<?php

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Exceptions\Balances\MaxCreditExceededException;
use App\Exceptions\Balances\SelfApprovalException;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Notifications\BalanceRequestDecided;
use App\Notifications\BalanceRequestSubmitted;
use App\Services\Balances\BalanceRequestService;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function balanceRequestService(): BalanceRequestService
{
    return app(BalanceRequestService::class);
}

it('submits a pending request with no ledger effect', function () {
    Notification::fake();

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $admin, $company);

    expect($request->status)->toBe(BalanceRequestStatus::Pending)
        ->and($request->journal_entry_id)->toBeNull();

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);
    expect($send->fresh()->balance_cents)->toBe(0);
});

it('notifies eligible approvers (but not the requester) when a request is submitted', function () {
    Notification::fake();

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $approver = User::factory()->create();
    $approver->assignRole('admin');
    $bystander = User::factory()->create(); // no relevant permission

    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $agent, $company);

    Notification::assertSentTo($approver, BalanceRequestSubmitted::class);
    Notification::assertNotSentTo($agent, BalanceRequestSubmitted::class);
    Notification::assertNotSentTo($bystander, BalanceRequestSubmitted::class);
});

it('approves a pending request and posts the correct ledger entry', function () {
    Notification::fake();

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $agent, $company);
    balanceRequestService()->approve($request, $admin);

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Approved)
        ->and($request->fresh()->journal_entry_id)->not->toBeNull()
        ->and($send->fresh()->balance_cents)->toBe(100000);

    Notification::assertSentTo($agent, BalanceRequestDecided::class);
});

it('blocks self-approval', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $agent, $company);

    expect(fn () => balanceRequestService()->approve($request, $agent))->toThrow(SelfApprovalException::class);
});

it('rejects a pending request with a reason', function () {
    Notification::fake();

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $agent, $company);
    balanceRequestService()->reject($request, $admin, 'No proof of payment');

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Rejected)
        ->and($request->fresh()->note)->toBe('No proof of payment')
        ->and($request->fresh()->journal_entry_id)->toBeNull();

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);
    expect($send->fresh()->balance_cents)->toBe(0);
});

it('cannot decide an already-decided request', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupPaid, $agent, 1000_00, $agent, $company);
    balanceRequestService()->approve($request, $admin);

    expect(fn () => balanceRequestService()->approve($request->fresh(), $admin))->toThrow(RuntimeException::class);
});

it('lets an admin act directly on behalf of a different beneficiary', function () {
    Notification::fake();

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $request = balanceRequestService()->actDirectly(BalanceRequestKind::TopupPaid, $agent, 500_00, $admin, $company);

    expect($request->status)->toBe(BalanceRequestStatus::Approved)
        ->and($request->requested_by)->toBe($admin->id)
        ->and($request->decided_by)->toBe($admin->id);

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);
    expect($send->fresh()->balance_cents)->toBe(50000);
});

it('blocks an admin from acting directly on their own behalf', function () {
    $admin = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    expect(fn () => balanceRequestService()->actDirectly(BalanceRequestKind::TopupPaid, $admin, 500_00, $admin, $company))
        ->toThrow(SelfApprovalException::class);
});

it('moves commission instantly for a sending agent with no separate approval', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();
    $ledger = app(LedgerService::class);

    // Fund some commission first, directly via the ledger (not a balance request).
    $commission = $ledger->accountFor($agent, LedgerAccountType::AgentCommission);
    $ledger->post(
        JournalType::ManualAdjustment,
        [[$ledger->system(LedgerAccountType::CommissionExpense), 100_00], [$commission, -100_00]],
        null,
        $admin
    );

    $request = balanceRequestService()->moveCommissionInstant($agent, 100_00);

    expect($request->status)->toBe(BalanceRequestStatus::Approved)
        ->and($request->requested_by)->toBe($agent->id)
        ->and($request->decided_by)->toBe($agent->id);

    $send = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend);
    expect($send->fresh()->balance_cents)->toBe(10000)
        ->and($commission->fresh()->balance_cents)->toBe(0);
});

it('blocks topup_credit past the max credit limit', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent, 'max_credit_cents' => 500_00]);
    $admin = User::factory()->create();

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupCredit, $agent, 600_00, $agent);

    expect(fn () => balanceRequestService()->approve($request, $admin))->toThrow(MaxCreditExceededException::class);
});

it('allows topup_credit within the settings default when the user has no override', function () {
    Settings::set('credit.default_max_cents', 1000_00);

    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $admin = User::factory()->create();

    $request = balanceRequestService()->submit(BalanceRequestKind::TopupCredit, $agent, 800_00, $agent);
    balanceRequestService()->approve($request, $admin);

    $credit = app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentCredit);
    expect($credit->fresh()->balance_cents)->toBe(80000);
});
