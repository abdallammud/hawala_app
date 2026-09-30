<?php

use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

it('lists the reports an admin may run', function () {
    $response = $this->actingAs(makeAdmin())->get(route('reports.index'));

    $response->assertOk();

    foreach (app(ReportRegistry::class)->all() as $report) {
        $response->assertSee($report->title());
    }
});

it('opens every report page for an admin', function () {
    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($this->branch, fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );
    $queue = app(PayoutQueueService::class);
    $queue->claim($transfer, $payer);
    $queue->markPaid($transfer->fresh(), $payer, H::payoutAccountFor($payer));

    $admin = makeAdmin();

    foreach (app(ReportRegistry::class)->all() as $report) {
        $this->actingAs($admin)
            ->get(route('reports.show', $report->key()))
            ->assertOk()
            ->assertSee($report->title());
    }
});

it('returns 404 for a report that does not exist', function () {
    $this->actingAs(makeAdmin())
        ->get(route('reports.show', 'not-a-report'))
        ->assertNotFound();
});

it('returns 403 when the user lacks that report permission', function () {
    $agent = H::sendingAgent(fundCents: 0);

    // Sending agents have no profit permission (BUILD.md §10).
    expect($agent->can('reports.profit'))->toBeFalse();

    $this->actingAs($agent)->get(route('reports.show', 'profit'))->assertForbidden();
    $this->actingAs($agent)->get(route('reports.export', 'profit'))->assertForbidden();
});

it('hides the reports index from a user with no report permissions', function () {
    $role = Role::create(['name' => 'no-reports']);
    $role->syncPermissions(['dashboard.view']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
});

it('exports a report as CSV with money written as plain decimals', function () {
    $sender = H::sendingAgent(fundCents: 500000);

    app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 123456]), (string) Str::uuid()
    );

    $response = $this->actingAs(makeAdmin())->get(route('reports.export', 'transfers'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toStartWith('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('Code')
        // $1,234.56 must export as 1234.56, not 123456 and not "$1,234.56".
        ->and($lines[1])->toContain('1234.56')
        ->and($lines[1])->not->toContain('$');
});

it('names the export file after the report and its date range', function () {
    $response = $this->actingAs(makeAdmin())
        ->get(route('reports.export', 'transfers').'?from=2026-01-01&to=2026-01-31');

    $response->assertOk()
        ->assertDownload('transfers-2026-01-01-to-2026-01-31.csv');
});

it('filters a report by date range', function () {
    $sender = H::sendingAgent(fundCents: 500000);

    app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(['amount_cents' => 10000]), (string) Str::uuid()
    );

    $admin = makeAdmin();

    // A window that ended before the transfer existed.
    $this->actingAs($admin)
        ->get(route('reports.show', 'transfers').'?from=2020-01-01&to=2020-01-31')
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());

    $this->actingAs($admin)
        ->get(route('reports.show', 'transfers'))
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
});
