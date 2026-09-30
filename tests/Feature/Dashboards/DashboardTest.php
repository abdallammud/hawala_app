<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\LedgerAccount;
use App\Services\DashboardService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

it('shows a sending agent their own balances and today figures', function () {
    $agent = H::sendingAgent(fundCents: 50000);

    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $data = app(DashboardService::class)->forSendingAgent($agent);

    expect($data['send_balance'])->toBe(50000 - 10500)
        ->and($data['commission'])->toBe(250)
        ->and($data['credit_owed'])->toBe(0)
        ->and($data['today_count'])->toBe(1)
        ->and($data['today_amount'])->toBe(10000)
        ->and($data['recent'])->toHaveCount(1);
});

it('counts only the outstanding part of an uncollected transfer', function () {
    $agent = H::sendingAgent(fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 30000, 'is_uncollected' => true]), (string) Str::uuid()
    );

    $data = app(DashboardService::class)->forSendingAgent($agent);

    expect($data['uncollected'])->toBe($transfer->outstandingCents())
        ->and($data['uncollected'])->toBeGreaterThan(0);
});

it('shows a payout agent their float, their branch queue and their claims', function () {
    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($this->branch, fundCents: 400000);

    $queued = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );
    $claimed = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 20000]), (string) Str::uuid()
    );

    app(PayoutQueueService::class)->claim($claimed, $payer);

    $data = app(DashboardService::class)->forPayoutAgent($payer);

    expect($data['payout_balance'])->toBe(400000)
        ->and($data['queue_count'])->toBe(1)
        ->and($data['queue_amount'])->toBe(10000)
        ->and($data['claimed'])->toHaveCount(1)
        ->and($data['claimed']->first()->id)->toBe($claimed->id)
        ->and($data['paid_today'])->toBe(0);

    expect($queued->fresh()->status)->toBe(TransferStatus::Queued);
});

it('does not show a payout agent another branch queue', function () {
    $sender = H::sendingAgent(fundCents: 500000);
    $other = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_active' => true]);
    $payer = H::payoutAgent($other, fundCents: 100000);

    app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $data = app(DashboardService::class)->forPayoutAgent($payer);

    expect($data['queue_count'])->toBe(0)
        ->and($data['queue_amount'])->toBe(0);
});

it('the admin waiting-to-pay figure equals the transfers payable account', function () {
    $sender = H::sendingAgent(fundCents: 500000);

    app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );
    app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 20000]), (string) Str::uuid()
    );

    $data = app(DashboardService::class)->forAdmin();

    expect($data['waiting_to_pay'])->toBe(30000)
        ->and($data['today_count'])->toBe(2)
        ->and($data['today_volume'])->toBe(30000);
});

it('admin money available in Somalia is company accounts plus payout float', function () {
    $payer = H::payoutAgent($this->branch, fundCents: 250000);

    $somali = CompanyAccount::create([
        'name' => 'Premier Bank Mogadishu',
        'location' => 'somalia',
        'is_active' => true,
    ]);

    app(LedgerService::class)->post(
        JournalType::ManualAdjustment,
        [
            [app(LedgerService::class)->accountFor($somali, LedgerAccountType::Company), 100000],
            [app(LedgerService::class)->system(LedgerAccountType::Adjustments), -100000],
        ],
        null,
        makeAdmin()
    );

    $data = app(DashboardService::class)->forAdmin();

    expect($data['somalia_company'])->toBe(100000)
        ->and($data['payout_float'])->toBe(250000)
        ->and($data['somalia_available'])->toBe(350000);
});

it('admin profit today equals fees less both commission shares', function () {
    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($this->branch, fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $queue->claim($transfer, $payer);
    $transfer = $queue->markPaid($transfer->fresh(), $payer, H::payoutAccountFor($payer));

    $data = app(DashboardService::class)->forAdmin();

    expect($data['today_fees'])->toBe($transfer->fee_cents)
        ->and($data['today_profit'])->toBe(
            $transfer->fee_cents - $transfer->sending_share_cents - $transfer->payout_share_cents
        );
});

it('viewing a dashboard never creates a ledger account', function () {
    $agent = H::sendingAgent(fundCents: 0);

    $before = LedgerAccount::query()->count();

    app(DashboardService::class)->forSendingAgent($agent);
    app(DashboardService::class)->forAdmin();

    expect(LedgerAccount::query()->count())->toBe($before);
});

it('routes each user type to its own dashboard view', function () {
    $admin = makeAdmin();
    $sender = H::sendingAgent(fundCents: 10000);
    $payer = H::payoutAgent($this->branch, fundCents: 10000);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()->assertViewIs('dashboards.admin');

    $this->actingAs($sender)->get(route('dashboard'))
        ->assertOk()->assertViewIs('dashboards.sending-agent');

    $this->actingAs($payer)->get(route('dashboard'))
        ->assertOk()->assertViewIs('dashboards.payout-agent');
});

it('requires a login to see a dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
