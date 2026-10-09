<?php

use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Transfers\TransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Guards against N+1: query counts must not grow with the number of rows.
 *
 * Each page is measured twice against datasets of different sizes. A page
 * that eager-loads properly costs the same either way; an N+1 costs more on
 * the bigger one. That is a far more robust check than asserting a magic
 * number, which would need updating on every unrelated change.
 */
beforeEach(function () {
    Notification::fake();
    $this->seed(DemoSeeder::class);

    $this->admin = User::where('email', 'admin@bluestar.test')->firstOrFail();
});

/** @return int queries run while rendering $path */
function countQueries(object $test, string $path): int
{
    DB::flushQueryLog();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    // The test clock jumps a day; keep the session fresh so the idle timeout stays out of it.
    $test->actingAs($test->admin)->withSession(['last_activity_at' => now()->timestamp])->get($path)->assertOk();

    // Stop counting before the next measurement.
    DB::listen(fn () => null);

    return $count;
}

$pages = [
    '/dashboard',
    '/transfers',
    '/users',
    '/audit-log',
    '/reports/transfers',
    '/reports/commissions',
    '/reports/cancellations',
    '/reports/uncollected',
    '/reports/company-accounts',
    '/reports/branch-summary',
    '/reports/payouts-by-method',
    '/reports/credit',
    '/reports/large-transfers',
    '/reports/profit',
];

it('does not run more queries as the data grows', function () use ($pages) {
    $before = [];

    foreach ($pages as $path) {
        $before[$path] = countQueries($this, $path);
    }

    // Double the dataset: a second pass of the demo seeder's transfers.
    $this->travel(1)->days();
    Transfer::query()->count(); // warm
    $this->seed(DemoSeeder::class);        // idempotent, but re-runs the reads

    // Add genuinely new rows so the page really has more to render.
    $agent = User::where('email', 'ali@bluestar.test')->firstOrFail();
    $branch = Branch::where('name', 'Mogadishu')->firstOrFail();

    for ($i = 0; $i < 15; $i++) {
        app(TransferService::class)->create(
            $agent,
            $branch,
            [
                'sender_name' => "Extra Sender {$i}",
                'sender_phone' => '+4670999'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'receiver_name' => "Extra Receiver {$i}",
                'receiver_phone' => '+2526129'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'receiver_account_type_id' => null,
                'receiver_account_number' => null,
                'currency' => 'USD',
                'amount_cents' => 5000,
                'is_uncollected' => $i % 3 === 0,
                'note' => null,
            ],
            (string) Str::uuid(),
        );
    }

    $problems = [];

    foreach ($pages as $path) {
        $after = countQueries($this, $path);

        // A little slack for pagination counts and permission lookups, but a
        // real N+1 over 15 new rows blows well past this.
        if ($after > $before[$path] + 5) {
            $problems[] = sprintf('%s: %d -> %d queries', $path, $before[$path], $after);
        }
    }

    expect($problems)->toBe([], "possible N+1:\n".implode("\n", $problems));
});

it('renders every report without exceeding a sane query budget', function () {
    foreach (app(ReportRegistry::class)->all() as $report) {
        $count = countQueries($this, "/reports/{$report->key()}");

        // Reports are read-only aggregates; none should need dozens of trips.
        expect($count)->toBeLessThan(40, "{$report->key()} ran {$count} queries");
    }
});
