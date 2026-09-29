<?php

use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Notifications\ClaimExpired;
use App\Notifications\TransferAssigned;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCollectionService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

it('expires a stale claim back to the queue and notifies admins', function () {
    Settings::set('payout.claim_minutes', 30);

    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);

    // Wind the deadline into the past.
    $claimed->update(['claim_expires_at' => now()->subMinute()]);

    $count = app(PayoutQueueService::class)->expireDueClaims();

    expect($count)->toBe(1)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Queued)
        ->and($claimed->fresh()->claimed_by)->toBeNull()
        ->and($claimed->fresh()->claim_expires_at)->toBeNull();

    Notification::assertSentTo($admin, ClaimExpired::class);
});

it('leaves a claim alone while it is still within its window', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);

    expect(app(PayoutQueueService::class)->expireDueClaims())->toBe(0)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Claimed);
});

it('never expires a claim that was paid just before the deadline', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $claimed = $queue->claim($transfer, $payer);
    $claimed->update(['claim_expires_at' => now()->subMinute()]);
    $queue->markPaid($claimed->fresh(), $payer, $account);

    expect($queue->expireDueClaims())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Paid);
});

it('runs expiry through the artisan command', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);
    $claimed->update(['claim_expires_at' => now()->subMinute()]);

    Artisan::call('hawala:expire-claims');

    expect($claimed->fresh()->status)->toBe(TransferStatus::Queued)
        ->and(Artisan::output())->toContain('1 claim(s) expired');
});

it('lets an admin force-assign a queued transfer and notifies the agent', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $assigned = app(PayoutQueueService::class)->forceAssign($transfer, $payer, $admin);

    expect($assigned->status)->toBe(TransferStatus::Claimed)
        ->and($assigned->claimed_by)->toBe($payer->id)
        ->and($assigned->assigned_by)->toBe($admin->id);

    Notification::assertSentTo($payer, TransferAssigned::class);
});

it('lets the claimer release a transfer back to the queue', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $claimed = $queue->claim($transfer, $payer);
    $released = $queue->release($claimed, $payer);

    expect($released->status)->toBe(TransferStatus::Queued)
        ->and($released->claimed_by)->toBeNull();
});

it('records a collection in USD and marks fully collected when it covers amount + fee', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000, 'is_uncollected' => true]), (string) Str::uuid()
    );

    expect($transfer->is_uncollected)->toBeTrue()
        ->and($transfer->outstandingCents())->toBe(10500);

    $service = app(TransferCollectionService::class);

    $service->record($transfer, $agent, 5000, 'USD', now());
    expect($transfer->fresh()->collected_cents)->toBe(5000)
        ->and($transfer->fresh()->outstandingCents())->toBe(5500)
        ->and($transfer->fresh()->fully_collected_at)->toBeNull();

    $service->record($transfer->fresh(), $agent, 5500, 'USD', now());
    expect($transfer->fresh()->collected_cents)->toBe(10500)
        ->and($transfer->fresh()->outstandingCents())->toBe(0)
        ->and($transfer->fresh()->fully_collected_at)->not->toBeNull();
});

it('converts a non-USD collection at the market rate', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000, 'is_uncollected' => true]), (string) Str::uuid()
    );

    // EUR manual rate is 1.08, so €100.00 = $108.00
    $collection = app(TransferCollectionService::class)->record($transfer, $agent, 10000, 'EUR', now());

    expect($collection->amount_cents)->toBe(10800)
        ->and($collection->original_amount_cents)->toBe(10000)
        ->and($collection->currency)->toBe('EUR')
        ->and($transfer->fresh()->collected_cents)->toBe(10800);
});

it('keeps transfers_payable equal to the sum of open transfers', function () {
    $sender = H::sendingAgent(fundCents: 1000000);
    $payer = H::payoutAgent($this->branch, 1000000);
    $account = H::payoutAccountFor($payer);
    $service = app(TransferService::class);

    $a = $service->create($sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid());
    $b = $service->create($sender, $this->branch, H::transferPayload(['amount_cents' => 25000]), (string) Str::uuid());
    $service->create($sender, $this->branch, H::transferPayload(['amount_cents' => 5000]), (string) Str::uuid());

    // Pay one off — it should leave transfers_payable.
    $queue = app(PayoutQueueService::class);
    $queue->markPaid($queue->claim($a, $payer), $payer, $account);

    $openSum = Transfer::query()
        ->whereIn('status', [TransferStatus::AwaitingApproval, TransferStatus::Queued, TransferStatus::Claimed])
        ->sum('amount_cents');

    $payable = app(LedgerService::class)
        ->system(LedgerAccountType::TransfersPayable)->fresh()->balance_cents;

    expect($payable)->toBe((int) $openSum)->toBe(30000);
});
