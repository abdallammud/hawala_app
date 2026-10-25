<?php

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Exceptions\Balances\AlreadyApprovedException;
use App\Exceptions\Balances\MaxCreditExceededException;
use App\Exceptions\Balances\SelfApprovalException;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Notifications\BalanceRequestApprovalRecorded;
use App\Notifications\BalanceRequestDecided;
use App\Services\Balances\BalanceRequestService;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(BalanceRequestService::class);
    $this->agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $this->agent->assignRole('sending-agent');
    $this->adminA = creditAdmin();
    $this->adminB = creditAdmin();
    $this->adminC = creditAdmin();
});

function creditAdmin(): User
{
    $user = User::factory()->create(['type' => UserType::Staff]);
    $user->assignRole('admin');

    return $user;
}

function creditBalances(User $agent): array
{
    $ledger = app(LedgerService::class);

    return [
        'send' => $ledger->accountFor($agent, LedgerAccountType::AgentSend)->fresh()->balance_cents,
        'credit' => $ledger->accountFor($agent, LedgerAccountType::AgentCredit)->fresh()->balance_cents,
    ];
}

function ledgerSumIsZero(): bool
{
    return (int) JournalLine::query()->sum('amount_cents') === 0;
}

it('snapshots two required approvals on a credit request and one on other kinds', function () {
    $credit = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $paid = $this->service->submit(BalanceRequestKind::TopupPaid, $this->agent, 500_00, $this->agent, $company);

    expect($credit->approvals_required)->toBe(2)->and($paid->approvals_required)->toBe(1);
});

it('keeps a credit request pending and moves no money after one approval', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    $this->service->approve($request, $this->adminA);

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Pending)
        ->and($request->fresh()->journal_entry_id)->toBeNull()
        ->and($request->approvals()->count())->toBe(1)
        ->and(creditBalances($this->agent))->toBe(['send' => 0, 'credit' => 0])
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('posts once the second different person approves', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    $this->service->approve($request, $this->adminA);
    $this->service->approve($request, $this->adminB);

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Approved)
        ->and($request->fresh()->decided_by)->toBe($this->adminB->id)
        ->and(creditBalances($this->agent))->toBe(['send' => 500_00, 'credit' => 500_00])
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(ledgerSumIsZero())->toBeTrue();
});

it('does not let the same person approve twice', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    $this->service->approve($request, $this->adminA);

    expect(fn () => $this->service->approve($request, $this->adminA))->toThrow(AlreadyApprovedException::class)
        ->and($request->fresh()->status)->toBe(BalanceRequestStatus::Pending)
        ->and(creditBalances($this->agent)['credit'])->toBe(0);
});

it('does not let the requester approve, even as the second approver', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->adminA);

    $this->service->approve($request, $this->adminB);

    expect(fn () => $this->service->approve($request, $this->adminA))->toThrow(SelfApprovalException::class)
        ->and($request->fresh()->status)->toBe(BalanceRequestStatus::Pending);

    $this->service->approve($request, $this->adminC);
    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Approved);
});

it('does not let the beneficiary approve their own credit', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->adminA, 500_00, $this->adminB);

    expect(fn () => $this->service->approve($request, $this->adminA))->toThrow(SelfApprovalException::class);
});

it('no longer posts instantly when an admin records credit on behalf of an agent', function () {
    Notification::fake();

    $request = $this->service->actDirectly(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->adminA);

    expect($request->status)->toBe(BalanceRequestStatus::Pending)
        ->and(creditBalances($this->agent))->toBe(['send' => 0, 'credit' => 0]);

    // the recording admin can't be one of the approvers
    expect(fn () => $this->service->approve($request, $this->adminA))->toThrow(SelfApprovalException::class);

    $this->service->approve($request, $this->adminB);
    $this->service->approve($request, $this->adminC);

    expect(creditBalances($this->agent))->toBe(['send' => 500_00, 'credit' => 500_00]);
});

it('still approves a paid top-up in one step', function () {
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $request = $this->service->submit(BalanceRequestKind::TopupPaid, $this->agent, 300_00, $this->agent, $company);

    $this->service->approve($request, $this->adminA);

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Approved)
        ->and(creditBalances($this->agent)['send'])->toBe(300_00);
});

it('is ended by a single rejection even after one approval', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    $this->service->approve($request, $this->adminA);
    $this->service->reject($request, $this->adminB, 'Too high');

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Rejected)
        ->and(fn () => $this->service->approve($request, $this->adminC))->toThrow(RuntimeException::class)
        ->and(creditBalances($this->agent))->toBe(['send' => 0, 'credit' => 0]);
});

it('checks the credit limit at every approval and at the final post', function () {
    $this->agent->update(['max_credit_cents' => 400_00]);
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    expect(fn () => $this->service->approve($request, $this->adminA))->toThrow(MaxCreditExceededException::class)
        ->and($request->approvals()->count())->toBe(0);

    // limit is raised after the first approval, then lowered again before the second
    $this->agent->update(['max_credit_cents' => 600_00]);
    $this->service->approve($request, $this->adminA);
    $this->agent->update(['max_credit_cents' => 100_00]);

    expect(fn () => $this->service->approve($request, $this->adminB))->toThrow(MaxCreditExceededException::class)
        ->and($request->fresh()->status)->toBe(BalanceRequestStatus::Pending)
        ->and(creditBalances($this->agent)['credit'])->toBe(0);
});

it('keeps the number of approvals a request was created with when the setting changes', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);
    Settings::set('credit.approvals_required', 3);
    $later = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 100_00, $this->agent);

    $this->service->approve($request, $this->adminA);
    $this->service->approve($request, $this->adminB);

    expect($request->fresh()->status)->toBe(BalanceRequestStatus::Approved)
        ->and($later->approvals_required)->toBe(3);
});

it('never lets the setting go below two', function () {
    Settings::set('credit.approvals_required', 1);

    expect(BalanceRequestKind::TopupCredit->approvalsRequired())->toBe(2);
});

it('tells the other approvers about the first approval and the agent only about the final one', function () {
    Notification::fake();

    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);
    $this->service->approve($request, $this->adminA);

    Notification::assertSentTo($this->adminB, BalanceRequestApprovalRecorded::class);
    Notification::assertNotSentTo($this->adminA, BalanceRequestApprovalRecorded::class);
    Notification::assertNotSentTo($this->agent, BalanceRequestDecided::class);

    $this->service->approve($request, $this->adminB);

    Notification::assertSentTo($this->agent, BalanceRequestDecided::class);
});

it('shows progress in the inbox and hides Approve from someone who already approved', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);
    $this->service->approve($request, $this->adminA);

    $this->actingAs($this->adminA)->get('/balance-requests/inbox')
        ->assertOk()->assertSee('1 of 2 approvals')->assertSee('You approved')
        ->assertDontSee('/approve', false);

    $this->actingAs($this->adminB)->get('/balance-requests/inbox')
        ->assertOk()->assertSee('1 of 2 approvals')->assertSee('/approve', false);
});

it('answers a repeated approve click from the same admin with an error, not a second approval', function () {
    $request = $this->service->submit(BalanceRequestKind::TopupCredit, $this->agent, 500_00, $this->agent);

    $this->actingAs($this->adminA)->post("/balance-requests/{$request->id}/approve")->assertRedirect();
    $this->actingAs($this->adminA)->post("/balance-requests/{$request->id}/approve")->assertSessionHasErrors('status');

    expect(BalanceRequest::find($request->id)->approvals()->count())->toBe(1)
        ->and(BalanceRequest::find($request->id)->status)->toBe(BalanceRequestStatus::Pending);
});

it('says so when an admin records credit for an agent through the form', function () {
    $this->actingAs($this->adminA)->post('/balance-requests', [
        'kind' => 'topup_credit',
        'user_id' => $this->agent->id,
        'amount' => '250',
    ])->assertRedirect('/balance-requests')->assertSessionHas('status', fn ($m) => str_contains($m, '2 approvals'));

    expect(creditBalances($this->agent)['credit'])->toBe(0);
});

it('saves the approvals setting and refuses fewer than two', function () {
    $super = User::factory()->create(['type' => UserType::Staff]);
    $super->assignRole('super-admin');

    $form = [
        'fees_mode' => 'marginal', 'fees_min_fee' => '1', 'fees_large_transfer' => '5000', 'fees_refund_on_cancel' => '1',
        'commission_send_type' => 'percent', 'commission_send_value' => '40',
        'commission_payout_type' => 'percent', 'commission_payout_value' => '15',
        'currency_margin_bp' => '150', 'currency_max_rate_age_hours' => '48', 'payout_claim_minutes' => '45',
        'sms_driver' => 'hormuud', 'general_company_name' => 'Blue Star', 'security_idle_timeout_minutes' => 15,
        'general_timezone' => 'Africa/Mogadishu', 'general_duplicate_warning_minutes' => '15',
    ];

    $this->actingAs($super)->get('/setup/settings')->assertOk()->assertSee('Approvals needed for a credit top-up');

    $this->actingAs($super)->put('/setup/settings', $form + ['credit_approvals_required' => '1'])
        ->assertSessionHasErrors('credit_approvals_required');
    expect(Settings::get('credit.approvals_required'))->toBe(2);

    $this->actingAs($super)->put('/setup/settings', $form + ['credit_approvals_required' => '3'])->assertRedirect();
    expect(Settings::get('credit.approvals_required'))->toBe(3);
});
