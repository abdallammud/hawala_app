<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Reports\AgentStatementReport;
use App\Reports\CommissionsReport;
use App\Reports\ProfitReport;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

/** Filters covering everything that happens during a test. */
function allTimeFilters(array $overrides = []): ReportFilters
{
    return new ReportFilters(
        from: CarbonImmutable::now()->subYear(),
        to: CarbonImmutable::now()->addDay(),
        branchId: $overrides['branchId'] ?? null,
        agentId: $overrides['agentId'] ?? null,
        status: $overrides['status'] ?? null,
        accountType: $overrides['accountType'] ?? null,
        groupBy: $overrides['groupBy'] ?? null,
    );
}

/** One completed send → claim → pay, returning [sender, payer, transfer]. */
function paidTransfer(object $test, int $amountCents = 10000): array
{
    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($test->branch, fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $sender, $test->branch, H::transferPayload(['amount_cents' => $amountCents]), (string) Str::uuid()
    );

    $queue = app(PayoutQueueService::class);
    $queue->claim($transfer, $payer);
    $transfer = $queue->markPaid($transfer->fresh(), $payer, H::payoutAccountFor($payer));

    return [$sender, $payer, $transfer];
}

it('every registered report has a seeded permission and runs without error for an admin', function () {
    [$sender, $payer, $transfer] = paidTransfer($this);
    $admin = makeAdmin();

    $registry = app(ReportRegistry::class);

    expect($registry->all())->toHaveCount(13);

    foreach ($registry->all() as $report) {
        // A report gated on a permission nobody can be granted is unreachable.
        $this->assertTrue(
            Permission::where('name', $report->permission())->exists(),
            "permission {$report->permission()} is not seeded"
        );

        $rows = $report->rows(allTimeFilters(), $admin);

        expect($rows)->toBeInstanceOf(Collection::class);

        foreach ($rows as $row) {
            // Every row must supply exactly the declared columns, or the table
            // and the CSV export will silently disagree.
            foreach (array_keys($report->columns()) as $column) {
                $this->assertArrayHasKey($column, $row, "{$report->key()} row is missing column {$column}");
            }

            // Money columns must be integer cents — never a float or a string.
            foreach ($report->moneyColumns() as $column) {
                $this->assertIsInt($row[$column], "{$report->key()}.{$column} must be integer cents");
            }
        }

        expect($report->totals($rows))->toBeArray();
    }
})->group('reports');

it('report keys are unique so the registry can resolve a URL', function () {
    $keys = app(ReportRegistry::class)->all()->map(fn ($r) => $r->key());

    expect($keys->duplicates())->toBeEmpty();
});

it('only lists reports the user has permission for', function () {
    $admin = makeAdmin();
    $agent = H::sendingAgent(fundCents: 0);
    $registry = app(ReportRegistry::class);

    expect($registry->visibleTo($admin))->toHaveCount(13);

    $agentReports = $registry->visibleTo($agent);

    expect($agentReports->count())->toBeLessThan(13)
        ->and($agentReports->count())->toBeGreaterThan(0)
        ->and($agentReports->map(fn ($r) => $r->key()))->not->toContain('profit');
});

it('the transfers report shows a paid transfer and totals its money columns', function () {
    [$sender, $payer, $transfer] = paidTransfer($this, amountCents: 25000);

    $report = app(ReportRegistry::class)->find('transfers');
    $rows = $report->rows(allTimeFilters(), makeAdmin());

    expect($rows)->toHaveCount(1);

    $totals = $report->totals($rows);

    expect($totals['amount'])->toBe(25000)
        ->and($totals['fee'])->toBe($transfer->fee_cents);
});

it('the agent statement opens at the prior balance and closes at the current one', function () {
    $agent = H::sendingAgent(fundCents: 50000); // funded before the window

    // Move the funding into the past so it lands in the opening balance.
    JournalLine::query()->update(['created_at' => now()->subDays(10)]);

    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $filters = new ReportFilters(
        from: CarbonImmutable::now()->subDays(2)->startOfDay(),
        to: CarbonImmutable::now()->endOfDay(),
        agentId: $agent->id,
        accountType: LedgerAccountType::AgentSend->value,
    );

    $rows = app(AgentStatementReport::class)->rows($filters, makeAdmin());

    expect($rows->first()['type'])->toBe(__('Opening balance'))
        ->and($rows->first()['running'])->toBe(50000)
        ->and($rows->last()['type'])->toBe(__('Closing balance'))
        // $100 + $5 fee left the send balance.
        ->and($rows->last()['running'])->toBe(50000 - 10500);

    // The closing figure must equal the live account balance.
    expect($rows->last()['running'])->toBe(
        app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend)->fresh()->balance_cents
    );
});

it('an agent cannot pull another agent statement', function () {
    $agent = H::sendingAgent(fundCents: 50000);
    $other = H::sendingAgent(fundCents: 50000);

    $rows = app(AgentStatementReport::class)->rows(
        allTimeFilters(['agentId' => $other->id, 'accountType' => LedgerAccountType::AgentSend->value]),
        $agent
    );

    expect($rows)->toBeEmpty();

    // Their own statement still works.
    $own = app(AgentStatementReport::class)->rows(
        allTimeFilters(['agentId' => $agent->id, 'accountType' => LedgerAccountType::AgentSend->value]),
        $agent
    );

    expect($own)->not->toBeEmpty();
});

it('profit comes off the ledger, so fees and commission match the transfer', function () {
    [$sender, $payer, $transfer] = paidTransfer($this, amountCents: 10000);

    $rows = app(ProfitReport::class)->rows(allTimeFilters(), makeAdmin());

    // $100 at 5% = $5 fee; sender takes 50% of it, payer 20%.
    expect((int) $rows->sum('fee_income'))->toBe($transfer->fee_cents)
        ->and((int) $rows->sum('commission_expense'))
        ->toBe($transfer->sending_share_cents + $transfer->payout_share_cents);

    // Net must be the stated identity on every row.
    foreach ($rows as $row) {
        expect($row['net'])->toBe(
            $row['fee_income'] + $row['fx_income']
            - $row['commission_expense'] - $row['bank_charges'] - $row['adjustments']
        );
    }
});

it('profit includes a manual adjustment that no transfer knows about', function () {
    [$sender, $payer, $transfer] = paidTransfer($this, amountCents: 10000);

    $report = app(ProfitReport::class);
    $before = (int) $report->rows(allTimeFilters(), makeAdmin())->sum('net');

    // A $20 bank charge is a cost the transfers table cannot show.
    $ledger = app(LedgerService::class);
    $company = CompanyAccount::create([
        'name' => 'Charges source', 'location' => 'europe', 'is_active' => true,
    ]);

    $ledger->post(
        JournalType::ManualAdjustment,
        [
            [$ledger->system(LedgerAccountType::BankCharges), 2000],
            [$ledger->accountFor($company, LedgerAccountType::Company), -2000],
        ],
        null,
        makeAdmin()
    );

    $rows = $report->rows(allTimeFilters(), makeAdmin());

    expect((int) $rows->sum('bank_charges'))->toBe(2000)
        ->and((int) $rows->sum('net'))->toBe($before - 2000);
});

it('the commissions report reports what each agent earned', function () {
    [$sender, $payer, $transfer] = paidTransfer($this, amountCents: 10000);

    $rows = app(CommissionsReport::class)->rows(allTimeFilters(), makeAdmin());

    $senderRow = $rows->firstWhere('agent', $sender->name);
    $payerRow = $rows->firstWhere('agent', $payer->name);

    expect($senderRow['earned'])->toBe($transfer->sending_share_cents)
        ->and($senderRow['balance'])->toBe($transfer->sending_share_cents)
        ->and($payerRow['earned'])->toBe($transfer->payout_share_cents);
});

it('an agent only sees their own commission row', function () {
    [$sender, $payer] = paidTransfer($this);

    $rows = app(CommissionsReport::class)->rows(allTimeFilters(), $sender);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['agent'])->toBe($sender->name);
});

it('the cancellations report lists both cancellations and claim time-outs', function () {
    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($this->branch, fundCents: 500000);
    $admin = makeAdmin();

    // One cancelled transfer.
    $cancelled = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );
    app(TransferCancellationService::class)
        ->cancel($cancelled, $admin, 'Wrong receiver');

    // One claim that times out.
    $expired = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 20000]), (string) Str::uuid()
    );
    app(PayoutQueueService::class)->claim($expired, $payer);
    $expired->fresh()->update(['claim_expires_at' => now()->subMinute()]);
    app(PayoutQueueService::class)->expireDueClaims();

    $rows = app(ReportRegistry::class)->find('cancellations')->rows(allTimeFilters(), $admin);

    expect($rows)->toHaveCount(2);

    $kinds = $rows->pluck('kind');

    expect($kinds)->toContain(__('Cancelled'))
        ->and($kinds)->toContain(__('Claim timed out'));

    $timeout = $rows->firstWhere('kind', __('Claim timed out'));

    // The expiry must remember who held the claim, even though the
    // transfer row no longer does.
    expect($timeout['who'])->toBe($payer->name)
        ->and($timeout['code'])->toBe($expired->code)
        ->and($timeout['amount'])->toBe(20000);
});

it('the uncollected report shows only the outstanding part', function () {
    $sender = H::sendingAgent(fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 30000, 'is_uncollected' => true]), (string) Str::uuid()
    );

    $rows = app(ReportRegistry::class)->find('uncollected')->rows(allTimeFilters(), makeAdmin());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['outstanding'])->toBe($transfer->outstandingCents());
});
