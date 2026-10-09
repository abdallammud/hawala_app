<?php

use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CancellationRequest;
use App\Models\Transfer;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

function postTransfer(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => Branch::query()->first()->id,
        'sender_name' => 'Ali Hassan',
        'sender_phone' => '+46701234567',
        'sender_country' => 'Sweden',
        'sender_city' => 'Stockholm',
        'receiver_name' => 'Farah Omar',
        'receiver_phone' => '+252611234567',
        'receiver_country' => 'Somalia',
        'receiver_city' => 'Mogadishu',
        'currency' => 'USD',
        'payout_currency' => 'USD',
        'mode' => 'receive',
        'payout_amount' => '100',
        'confirm_duplicate' => '1',
    ], $overrides);
}

it('blocks a payout agent from the create form', function () {
    $payer = H::payoutAgent($this->branch);

    $this->actingAs($payer)->get('/transfers/create')->assertForbidden();
});

it('lets a sending agent create a transfer through the form', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)->post('/transfers', postTransfer())->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();
    expect($transfer->status)->toBe(TransferStatus::Queued)
        ->and($transfer->amount_cents)->toBe(10000)
        ->and($transfer->sending_agent_id)->toBe($agent->id);
});

it('warns about a duplicate instead of creating it, then allows it on confirm', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    // First one goes through.
    $this->actingAs($agent)->post('/transfers', postTransfer());
    expect(Transfer::query()->count())->toBe(1);

    // Second without confirmation is bounced back with a warning.
    $this->actingAs($agent)
        ->post('/transfers', postTransfer(['confirm_duplicate' => '0']))
        ->assertRedirect()
        ->assertSessionHas('duplicate_warning');

    expect(Transfer::query()->count())->toBe(1);

    // With confirmation it goes through.
    $this->actingAs($agent)->post('/transfers', postTransfer());
    expect(Transfer::query()->count())->toBe(2);
});

it('shows a friendly message with a top-up link when the balance is short', function () {
    $agent = H::sendingAgent(fundCents: 1000); // $10 only

    $this->actingAs($agent)
        ->post('/transfers', postTransfer(['payout_amount' => '500']))
        ->assertRedirect()
        ->assertSessionHas('insufficient_balance');

    expect(Transfer::query()->count())->toBe(0);
});

it('scopes the list so an agent sees only their own transfers', function () {
    $mine = H::sendingAgent(fundCents: 100000);
    $theirs = H::sendingAgent(fundCents: 100000);
    $service = app(TransferService::class);

    $a = $service->create($mine, $this->branch, H::transferPayload(['receiver_name' => 'Mine Receiver']), (string) Str::uuid());
    $b = $service->create($theirs, $this->branch, H::transferPayload(['receiver_name' => 'Their Receiver']), (string) Str::uuid());

    $this->actingAs($mine)->get('/transfers')
        ->assertOk()
        ->assertSee($a->code)
        ->assertDontSee($b->code);
});

it('forbids an agent from opening another agent\'s transfer', function () {
    $mine = H::sendingAgent(fundCents: 100000);
    $theirs = H::sendingAgent(fundCents: 100000);

    $other = app(TransferService::class)->create($theirs, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($mine)->get("/transfers/{$other->id}")->assertForbidden();
});

it('lets a payout agent see branch transfers but not other branches', function () {
    $otherBranch = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_active' => true]);
    $sender = H::sendingAgent(fundCents: 200000);
    $payer = H::payoutAgent($this->branch, 100000);

    $service = app(TransferService::class);
    $mineBranch = $service->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    $notMine = $service->create($sender, $otherBranch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($payer)->get("/transfers/{$mineBranch->id}")->assertOk();
    $this->actingAs($payer)->get("/transfers/{$notMine->id}")->assertForbidden();
});

it('runs claim and mark-paid through the queue screen', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($payer)->get('/queue')->assertOk()->assertSee($transfer->code);

    $this->actingAs($payer)->post("/queue/{$transfer->id}/claim")->assertRedirect();
    expect($transfer->fresh()->status)->toBe(TransferStatus::Claimed);

    $this->actingAs($payer)->post("/queue/{$transfer->id}/mark-paid", [
        'payout_account_id' => $account->id,
        'payout_reference' => 'EVC-123',
    ])->assertRedirect();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Paid);
});

it('stops a payout agent claiming a transfer for another branch', function () {
    $otherBranch = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_active' => true]);
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($otherBranch, 100000);

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($payer)->post("/queue/{$transfer->id}/claim")->assertForbidden();
    expect($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});

it('lets a sending agent cancel their own queued transfer but not a claimed one', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $service = app(TransferService::class);

    $queued = $service->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    $this->actingAs($sender)->post("/transfers/{$queued->id}/cancel", ['reason' => 'Customer left'])->assertRedirect();
    expect($queued->fresh()->status)->toBe(TransferStatus::Cancelled);

    $claimed = $service->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    app(PayoutQueueService::class)->claim($claimed, $payer);

    $this->actingAs($sender)->post("/transfers/{$claimed->id}/cancel", ['reason' => 'too late'])->assertForbidden();
    expect($claimed->fresh()->status)->toBe(TransferStatus::Claimed);
});

it('routes a claimed cancellation through the request flow and an admin approves it', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    app(PayoutQueueService::class)->claim($transfer, $payer);

    $this->actingAs($sender)->post("/transfers/{$transfer->id}/request-cancel", ['reason' => 'Sender changed mind'])
        ->assertRedirect();

    $request = CancellationRequest::query()->firstOrFail();

    $this->actingAs($admin)->get('/cancellation-requests')->assertOk()->assertSee($transfer->code);
    $this->actingAs($admin)->post("/cancellation-requests/{$request->id}/approve")->assertRedirect();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Cancelled);
});

it('lets an admin approve a held large transfer', function () {
    Settings::set('fees.large_transfer_cents', 300000);
    Settings::set('fees.hold_large_transfers', true);

    $sender = H::sendingAgent(fundCents: 1000000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );

    $this->actingAs($admin)->post("/transfers/{$transfer->id}/approve-large")->assertRedirect();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});

it('records an uncollected payment through the transfer page', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['is_uncollected' => true]), (string) Str::uuid()
    );

    $this->actingAs($agent)->post("/transfers/{$transfer->id}/collections", [
        'amount' => '105',
        'currency' => 'USD',
        'received_at' => now()->toDateString(),
    ])->assertRedirect();

    expect($transfer->fresh()->collected_cents)->toBe(10500)
        ->and($transfer->fresh()->fully_collected_at)->not->toBeNull();
});

it('lets an admin force-assign from the queue', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($admin)->post("/queue/{$transfer->id}/force-assign", ['user_id' => $payer->id])
        ->assertRedirect();

    expect($transfer->fresh()->claimed_by)->toBe($payer->id)
        ->and($transfer->fresh()->assigned_by)->toBe($admin->id);
});

it('refuses to force-assign to an agent in a different branch', function () {
    $otherBranch = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_active' => true]);
    $sender = H::sendingAgent(fundCents: 100000);
    $wrongBranchAgent = H::payoutAgent($otherBranch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($admin)->post("/queue/{$transfer->id}/force-assign", ['user_id' => $wrongBranchAgent->id])
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});

it('expires stale claims lazily when the queue page loads', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);

    $transfer = app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);
    $claimed->update(['claim_expires_at' => now()->subMinute()]);

    $this->actingAs($payer)->get('/queue')->assertOk();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});
