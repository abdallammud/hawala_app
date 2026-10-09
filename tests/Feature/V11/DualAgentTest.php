<?php

use App\Enums\BalanceRequestKind;
use App\Enums\CommissionType;
use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Enums\UserType;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\CancellationRequest;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

/** One person who sends and pays out: 50% sending share, 20% payout share. */
function dualAgent(int $send = 100000, int $payout = 100000): User
{
    $user = User::factory()->create([
        'type' => UserType::DualAgent,
        'branch_id' => test()->branch->id,
        'send_commission_type' => CommissionType::Percent,
        'send_commission_value' => 5000,
        'payout_commission_type' => CommissionType::Percent,
        'payout_commission_value' => 2000,
    ]);
    $user->assignRole('sending-payout-agent');
    H::fund($user, LedgerAccountType::AgentSend, $send);
    H::fund($user, LedgerAccountType::AgentPayout, $payout);

    return $user;
}

function dualBalance(User $user, LedgerAccountType $type): int
{
    return app(LedgerService::class)->accountFor($user, $type)->fresh()->balance_cents;
}

function dualSend(User $user): Transfer
{
    return app(TransferService::class)->create($user, test()->branch, H::transferPayload(), (string) Str::uuid());
}

// --- Self-claim control ----------------------------------------------------

it('stops a user claiming a transfer they sent themselves, by default', function () {
    $dual = dualAgent();
    $transfer = dualSend($dual);

    $this->actingAs($dual)->post("/queue/{$transfer->id}/claim")->assertForbidden();

    expect(fn () => app(PayoutQueueService::class)->claim($transfer, $dual))
        ->toThrow(TransferStateException::class)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});

it('hides the Claim button on your own transfer and says why', function () {
    $dual = dualAgent();
    dualSend($dual);

    $this->actingAs($dual)->get('/queue')->assertOk()
        ->assertDontSee('/claim')
        ->assertSee('You sent this');
});

it('does not let an admin assign a transfer to the person who sent it', function () {
    $dual = dualAgent();
    $transfer = dualSend($dual);

    expect(fn () => app(PayoutQueueService::class)->forceAssign($transfer, $dual, makeAdmin()))
        ->toThrow(TransferStateException::class);

    $this->actingAs(makeAdmin())->post("/queue/{$transfer->id}/force-assign", ['user_id' => $dual->id])
        ->assertSessionHasErrors('status');
});

it('still lets a different payout agent claim a dual agent’s transfer', function () {
    $dual = dualAgent();
    $transfer = dualSend($dual);
    $other = H::payoutAgent($this->branch);

    $claimed = app(PayoutQueueService::class)->claim($transfer, $other);

    expect($claimed->claimed_by)->toBe($other->id);
});

it('re-checks the rule when marking paid, in case the setting changed after the claim', function () {
    Settings::set('payout.allow_self_claim', true);
    $dual = dualAgent();
    $account = H::payoutAccountFor($dual);
    $queue = app(PayoutQueueService::class);

    $claimed = $queue->claim(dualSend($dual), $dual);
    Settings::set('payout.allow_self_claim', false);

    expect(fn () => $queue->markPaid($claimed, $dual, $account))->toThrow(TransferStateException::class)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Claimed);
});

// --- The same-user money scenario (setting on) -----------------------------

it('keeps every balance right when one user sends and pays the same transfer', function () {
    Settings::set('payout.allow_self_claim', true);
    $dual = dualAgent(send: 100000, payout: 100000);
    $account = H::payoutAccountFor($dual);
    $queue = app(PayoutQueueService::class);

    // $100 + 5% fee: total debit $105; sending share 50% of fee = $2.50.
    $transfer = dualSend($dual);
    $transfer = $queue->markPaid($queue->claim($transfer, $dual), $dual, $account);

    // Payout share is 20% of the $2.50 left = $0.50; company keeps $2.00.
    expect($transfer->sending_share_cents)->toBe(250)
        ->and($transfer->payout_share_cents)->toBe(50)
        ->and($transfer->company_share_cents)->toBe(200)
        ->and($transfer->sending_share_cents + $transfer->payout_share_cents + $transfer->company_share_cents)->toBe($transfer->fee_cents);

    expect(dualBalance($dual, LedgerAccountType::AgentSend))->toBe(100000 - 10500)
        ->and(dualBalance($dual, LedgerAccountType::AgentPayout))->toBe(100000 - 10000)
        // Both shares land in the one commission balance.
        ->and(dualBalance($dual, LedgerAccountType::AgentCommission))->toBe(250 + 50);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('splits the commissions report by what earned it', function () {
    Settings::set('payout.allow_self_claim', true);
    $dual = dualAgent();
    $queue = app(PayoutQueueService::class);
    $queue->markPaid($queue->claim(dualSend($dual), $dual), $dual, H::payoutAccountFor($dual));

    $filters = new ReportFilters(CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
    $row = app(ReportRegistry::class)->find('commissions')->rows($filters, makeAdmin())
        ->firstWhere('agent', $dual->name);

    expect($row['earned'])->toBe(300)
        ->and($row['earned_sending'])->toBe(250)
        ->and($row['earned_payout'])->toBe(50);
});

it('finds a transfer by whoever sent, claimed or paid it', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch);
    $queue = app(PayoutQueueService::class);
    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    $queue->markPaid($queue->claim($transfer, $payer), $payer, H::payoutAccountFor($payer));

    $report = app(ReportRegistry::class)->find('transfers');

    foreach ([$sender, $payer] as $who) {
        $filters = new ReportFilters(CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay(), agentId: $who->id);
        expect($report->rows($filters, makeAdmin()))->toHaveCount(1);
    }
});

// --- Maker ≠ checker --------------------------------------------------------

it('stops a sender approving their own held large transfer', function () {
    Settings::set('fees.hold_large_transfers', true);
    Settings::set('fees.large_transfer_cents', 5000);

    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo('transfers.approve_large');
    $transfer = dualSend($agent);

    expect($transfer->status)->toBe(TransferStatus::AwaitingApproval);

    $service = app(TransferCancellationService::class);

    expect(fn () => $service->approveLarge($transfer, $agent))->toThrow(TransferStateException::class)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::AwaitingApproval);

    expect($service->approveLarge($transfer, makeAdmin())->status)->toBe(TransferStatus::Queued);
});

it('hides the approve button from the sender of a held transfer', function () {
    Settings::set('fees.hold_large_transfers', true);
    Settings::set('fees.large_transfer_cents', 5000);

    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo(['transfers.approve_large', 'transfers.view_all']);
    $transfer = dualSend($agent);

    $this->actingAs($agent)->get("/transfers/{$transfer->id}")->assertOk()->assertDontSee('Approve and queue');
    $this->actingAs(makeAdmin())->get("/transfers/{$transfer->id}")->assertOk()->assertSee('Approve and queue');
});

it('stops a requester approving their own cancellation request', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo('transfers.approve_cancel');
    $payer = H::payoutAgent($this->branch);

    $transfer = app(PayoutQueueService::class)->claim(dualSend($agent), $payer);
    $service = app(TransferCancellationService::class);
    $request = $service->requestCancellation($transfer, $agent, 'Customer changed mind');

    expect(fn () => $service->approveCancellation($request, $agent))->toThrow(TransferStateException::class)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Claimed)
        ->and(CancellationRequest::find($request->id)->status->value)->toBe('pending');

    $service->approveCancellation($request, makeAdmin());

    expect($transfer->fresh()->status)->toBe(TransferStatus::Cancelled);
});

// --- The dual-agent user itself -----------------------------------------------

it('lets a dual agent request both top-ups and payout funding', function () {
    $kinds = array_keys(BalanceRequestKind::requestableBy(dualAgent()));

    expect($kinds)->toContain('topup_paid', 'topup_credit', 'payout_funding', 'payout_return')
        ->and($kinds)->not->toContain('credit_repayment');
});

it('gives a dual agent one dashboard with both sets of balances', function () {
    $dual = dualAgent(send: 50000, payout: 70000);

    $this->actingAs($dual)->get('/dashboard')->assertOk()
        ->assertSee('Send balance')->assertSee('$500.00')
        ->assertSee('Payout balance')->assertSee('$700.00');
});

it('shows a dual agent their payout balance in the queue and payout accounts on their profile', function () {
    $dual = dualAgent(payout: 70000);

    $this->actingAs($dual)->get('/queue')->assertOk()->assertSee('$700.00');
    $this->actingAs(makeAdmin())->get("/users/{$dual->id}/edit")->assertOk()->assertSee('Payout accounts');
});

it('can be assigned a transfer from someone else by an admin', function () {
    $dual = dualAgent();
    $sender = H::sendingAgent(fundCents: 100000);
    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());

    $assigned = app(PayoutQueueService::class)->forceAssign($transfer, $dual, makeAdmin());

    expect($assigned->claimed_by)->toBe($dual->id);
});
