<?php

use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Services\Ledger\LedgerService;
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

function balanceOf($user, LedgerAccountType $type): int
{
    return app(LedgerService::class)->accountFor($user, $type)->fresh()->balance_cents;
}

it('creates a queued transfer and posts transfer_sent correctly', function () {
    $agent = H::sendingAgent(fundCents: 50000); // $500 send balance

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    // $100 amount, 5% fee = $5, total debit $105, sending share 50% of fee = $2.50
    expect($transfer->status)->toBe(TransferStatus::Queued)
        ->and($transfer->amount_cents)->toBe(10000)
        ->and($transfer->fee_cents)->toBe(500)
        ->and($transfer->total_debit_cents)->toBe(10500)
        ->and($transfer->sending_share_cents)->toBe(250)
        ->and($transfer->code)->toStartWith('HW-');

    expect(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(50000 - 10500)
        ->and(balanceOf($agent, LedgerAccountType::AgentCommission))->toBe(250);

    $payable = app(LedgerService::class)->system(LedgerAccountType::TransfersPayable);
    expect($payable->fresh()->balance_cents)->toBe(10000);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
});

it('is idempotent — the same key never posts twice', function () {
    $agent = H::sendingAgent(fundCents: 50000);
    $key = (string) Str::uuid();
    $service = app(TransferService::class);

    $first = $service->create($agent, $this->branch, H::transferPayload(), $key);
    $second = $service->create($agent, $this->branch, H::transferPayload(), $key);

    expect($second->id)->toBe($first->id)
        ->and(Transfer::query()->count())->toBe(1)
        ->and(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(50000 - 10500);
});

it('blocks a transfer the agent cannot afford and posts nothing', function () {
    $agent = H::sendingAgent(fundCents: 5000); // only $50

    expect(fn () => app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    ))->toThrow(InsufficientBalanceException::class);

    expect(Transfer::query()->count())->toBe(0)
        ->and(balanceOf($agent, LedgerAccountType::AgentSend))->toBe(5000);
});

it('runs the full send → claim → pay flow with exact balances', function () {
    $sender = H::sendingAgent(fundCents: 100000);   // $1,000 send
    $payer = H::payoutAgent($this->branch, 100000); // $1,000 payout
    $account = H::payoutAccountFor($payer);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $transfer = $queue->claim($transfer, $payer);

    expect($transfer->status)->toBe(TransferStatus::Claimed)
        ->and($transfer->claimed_by)->toBe($payer->id)
        ->and($transfer->claim_expires_at)->not->toBeNull();

    $transfer = $queue->markPaid($transfer, $payer, $account);

    // Ali & Farah numbers: fee $5, sending 50% = $2.50, payout 20% of the
    // remaining $2.50 = $0.50, company keeps $2.00.
    expect($transfer->status)->toBe(TransferStatus::Paid)
        ->and($transfer->payout_share_cents)->toBe(50)
        ->and($transfer->company_share_cents)->toBe(200)
        ->and(balanceOf($sender, LedgerAccountType::AgentSend))->toBe(100000 - 10500)
        ->and(balanceOf($sender, LedgerAccountType::AgentCommission))->toBe(250)
        ->and(balanceOf($payer, LedgerAccountType::AgentPayout))->toBe(100000 - 10000)
        ->and(balanceOf($payer, LedgerAccountType::AgentCommission))->toBe(50);

    $ledger = app(LedgerService::class);
    expect($ledger->system(LedgerAccountType::TransfersPayable)->fresh()->balance_cents)->toBe(0)
        ->and($ledger->system(LedgerAccountType::FeeIncome)->fresh()->balance_cents)->toBe(500)
        ->and($ledger->system(LedgerAccountType::CommissionExpense)->fresh()->balance_cents)->toBe(300);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);

    // The commission shares must sum to the fee (verify-ledger check 5).
    expect($transfer->sending_share_cents + $transfer->payout_share_cents + $transfer->company_share_cents)
        ->toBe($transfer->fee_cents);
});

it('blocks a claim when the payout agent cannot cover the amount', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $poorPayer = H::payoutAgent($this->branch, 5000); // only $50

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    expect(fn () => app(PayoutQueueService::class)->claim($transfer, $poorPayer))
        ->toThrow(InsufficientBalanceException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Queued);
});

it('allows an underfunded claim when payout.allow_negative is on', function () {
    Settings::set('payout.allow_negative', true);

    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 0);
    $account = H::payoutAccountFor($payer);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $transfer = $queue->markPaid($queue->claim($transfer, $payer), $payer, $account);

    expect($transfer->status)->toBe(TransferStatus::Paid)
        ->and(balanceOf($payer, LedgerAccountType::AgentPayout))->toBe(-10000);
});

it('lets only one of two agents claim the same transfer', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $first = H::payoutAgent($this->branch, 100000);
    $second = H::payoutAgent($this->branch, 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $queue->claim($transfer, $first);

    expect(fn () => $queue->claim($transfer->fresh(), $second))->toThrow(TransferStateException::class);

    expect($transfer->fresh()->claimed_by)->toBe($first->id);
});

it('refuses to mark paid using another agent\'s payout account', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $other = H::payoutAgent($this->branch, 100000);
    $othersAccount = H::payoutAccountFor($other);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $claimed = $queue->claim($transfer, $payer);

    expect(fn () => $queue->markPaid($claimed, $payer, $othersAccount))->toThrow(TransferStateException::class);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Claimed);
});

it('requires a reference when payout.reference_required is on', function () {
    Settings::set('payout.reference_required', true);

    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $claimed = $queue->claim($transfer, $payer);

    expect(fn () => $queue->markPaid($claimed, $payer, $account))->toThrow(TransferStateException::class);

    $paid = $queue->markPaid($claimed, $payer, $account, 'EVC-99887');
    expect($paid->status)->toBe(TransferStatus::Paid)
        ->and($paid->payout_reference)->toBe('EVC-99887');
});

it('holds a large transfer for approval when the setting is on', function () {
    Settings::set('fees.large_transfer_cents', 300000);
    Settings::set('fees.hold_large_transfers', true);

    $agent = H::sendingAgent(fundCents: 1000000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );

    expect($transfer->status)->toBe(TransferStatus::AwaitingApproval)
        ->and($transfer->is_large)->toBeTrue();
});

it('queues a large transfer but still flags it when hold is off', function () {
    Settings::set('fees.large_transfer_cents', 300000);
    Settings::set('fees.hold_large_transfers', false);

    $agent = H::sendingAgent(fundCents: 1000000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );

    expect($transfer->status)->toBe(TransferStatus::Queued)
        ->and($transfer->is_large)->toBeTrue();
});

it('detects a recent duplicate but does not block it', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $service = app(TransferService::class);

    $service->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    $duplicate = $service->findRecentDuplicate($agent, '+252611234567', 10000);
    expect($duplicate)->not->toBeNull();

    // Sending anyway still works.
    $second = $service->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());
    expect($second->status)->toBe(TransferStatus::Queued)
        ->and(Transfer::query()->count())->toBe(2);
});

it('creates a non-USD transfer with the snapshotted rate', function () {
    Settings::set('currency.margin_bp', 100);
    $agent = H::sendingAgent(fundCents: 1000000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['currency' => 'EUR', 'amount_cents' => 12000]), (string) Str::uuid()
    );

    expect($transfer->original_currency)->toBe('EUR')
        ->and($transfer->original_amount_cents)->toBe(12000)
        ->and($transfer->amount_cents)->toBe(12830)
        ->and($transfer->fx_income_cents)->toBe(136)
        ->and($transfer->margin_bp)->toBe(100)
        ->and((float) $transfer->market_rate)->toBe(1.08)
        // total_debit must equal amount + fee + fx (verify-ledger check 6)
        ->and($transfer->total_debit_cents)->toBe($transfer->amount_cents + $transfer->fee_cents + $transfer->fx_income_cents);
});
