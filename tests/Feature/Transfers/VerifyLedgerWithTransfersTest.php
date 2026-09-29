<?php

use App\Models\Transfer;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferService;
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

it('runs the transfer consistency checks now that the table exists', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);

    $service = app(TransferService::class);
    $queue = app(PayoutQueueService::class);

    $paid = $service->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    $queue->markPaid($queue->claim($paid, $payer), $payer, $account);

    $service->create($sender, $this->branch, H::transferPayload(['amount_cents' => 5000]), (string) Str::uuid());

    $exit = Artisan::call('hawala:verify-ledger');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Ledger OK')
        // The skip message must be gone now that transfers exist.
        ->and($output)->not->toContain('does not exist yet');
});

it('detects a transfers_payable drift against open transfers', function () {
    $sender = H::sendingAgent(fundCents: 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    // Corrupt the transfer's amount directly, bypassing the service, so the
    // ledger and the transfers table disagree.
    Transfer::query()->whereKey($transfer->id)->update(['amount_cents' => 99999]);

    expect(Artisan::call('hawala:verify-ledger'))->toBe(1);
    expect(Artisan::output())->toContain('transfers_payable');
});

it('detects commission shares that do not sum to the fee', function () {
    $sender = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($this->branch, 100000);
    $account = H::payoutAccountFor($payer);

    $queue = app(PayoutQueueService::class);
    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );
    $paid = $queue->markPaid($queue->claim($transfer, $payer), $payer, $account);

    Transfer::query()->whereKey($paid->id)->update(['company_share_cents' => 9999]);

    expect(Artisan::call('hawala:verify-ledger'))->toBe(1);
    expect(Artisan::output())->toContain('commission shares');
});

it('detects total_debit that does not equal amount + fee + fx', function () {
    $sender = H::sendingAgent(fundCents: 100000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    Transfer::query()->whereKey($transfer->id)->update(['total_debit_cents' => 12345]);

    expect(Artisan::call('hawala:verify-ledger'))->toBe(1);
    expect(Artisan::output())->toContain('total_debit_cents');
});
