<?php

use App\Enums\CancellationRequestStatus;
use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\JournalLine;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
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

it('cancels a queued transfer with a full refund when refund_on_cancel is on', function () {
    Settings::set('fees.refund_on_cancel', true);

    $agent = H::sendingAgent(fundCents: 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $cancelled = app(TransferCancellationService::class)->cancel($transfer, $admin, 'Customer changed mind');

    expect($cancelled->status)->toBe(TransferStatus::Cancelled)
        ->and($cancelled->fee_refunded)->toBeTrue()
        // Everything comes back: the agent is exactly where they started.
        ->and(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(100000)
        ->and(balanceOf($agent, LedgerAccountType::AgentCommission))->toBe(0);

    $ledger = app(LedgerService::class);
    expect($ledger->system(LedgerAccountType::TransfersPayable)->fresh()->balance_cents)->toBe(0)
        ->and($ledger->system(LedgerAccountType::FeeIncome)->fresh()->balance_cents)->toBe(0)
        ->and($ledger->system(LedgerAccountType::CommissionExpense)->fresh()->balance_cents)->toBe(0);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
});

it('keeps the fee but still claws back commission when refund_on_cancel is off', function () {
    Settings::set('fees.refund_on_cancel', false);

    $agent = H::sendingAgent(fundCents: 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $cancelled = app(TransferCancellationService::class)->cancel($transfer, $admin, 'Late cancellation');

    expect($cancelled->fee_refunded)->toBeFalse()
        // Agent gets the $100 back but eats the $5 fee.
        ->and(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(100000 - 500)
        // Commission is always reversed, even when the fee is kept.
        ->and(balanceOf($agent, LedgerAccountType::AgentCommission))->toBe(0);

    $ledger = app(LedgerService::class);
    expect($ledger->system(LedgerAccountType::FeeIncome)->fresh()->balance_cents)->toBe(500)
        ->and($ledger->system(LedgerAccountType::TransfersPayable)->fresh()->balance_cents)->toBe(0)
        ->and($ledger->system(LedgerAccountType::CommissionExpense)->fresh()->balance_cents)->toBe(0);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
});

it('cannot cancel a paid transfer', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $paid = $queue->markPaid($queue->claim($transfer, $payer), $payer, $account);

    expect(fn () => app(TransferCancellationService::class)->cancel($paid, $admin, 'too late'))
        ->toThrow(TransferStateException::class);
});

it('routes a claimed transfer through a cancellation request', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);

    $service = app(TransferCancellationService::class);
    $request = $service->requestCancellation($claimed, $sender, 'Sender asked to stop it');

    expect($request->status)->toBe(CancellationRequestStatus::Pending)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Claimed);

    $service->approveCancellation($request, $admin);

    expect($request->fresh()->status)->toBe(CancellationRequestStatus::Approved)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Cancelled)
        ->and(balanceOf($sender, LedgerAccountType::AgentSend))->toBe(100000);
});

it('leaves the transfer claimed when a cancellation request is rejected', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $claimed = app(PayoutQueueService::class)->claim($transfer, $payer);

    $service = app(TransferCancellationService::class);
    $request = $service->requestCancellation($claimed, $sender, 'changed mind');
    $service->rejectCancellation($request, $admin);

    expect($request->fresh()->status)->toBe(CancellationRequestStatus::Rejected)
        ->and($claimed->fresh()->status)->toBe(TransferStatus::Claimed);
});

it('rejects a held large transfer with a full refund even when refund_on_cancel is off', function () {
    Settings::set('fees.refund_on_cancel', false);
    Settings::set('fees.large_transfer_cents', 300000);
    Settings::set('fees.hold_large_transfers', true);

    $agent = H::sendingAgent(fundCents: 1000000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );
    expect($transfer->status)->toBe(TransferStatus::AwaitingApproval);

    $rejected = app(TransferCancellationService::class)->rejectLarge($transfer, $admin, 'Not approved');

    expect($rejected->status)->toBe(TransferStatus::Rejected)
        ->and($rejected->fee_refunded)->toBeTrue()
        // Full refund despite refund_on_cancel being off.
        ->and(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(1000000);
});

it('approves a held large transfer into the queue', function () {
    Settings::set('fees.large_transfer_cents', 300000);
    Settings::set('fees.hold_large_transfers', true);

    $agent = H::sendingAgent(fundCents: 1000000);
    $admin = makeAdmin();

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );

    $approved = app(TransferCancellationService::class)->approveLarge($transfer, $admin);

    expect($approved->status)->toBe(TransferStatus::Queued);
});
