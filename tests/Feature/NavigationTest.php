<?php

use App\Models\Branch;
use App\Models\User;
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

/**
 * The label of the highlighted sidebar item, read out of the sidebar <nav>
 * only — pagination links carry aria-current="page" too.
 */
function activeMenuLabels(string $html): array
{
    preg_match('/<nav class="flex-1 overflow-y-auto.*?<\/nav>/s', $html, $nav);

    preg_match_all(
        '/<a[^>]*aria-current="page"[^>]*>.*?<span class="truncate">([^<]+)<\/span>/s',
        $nav[0] ?? '',
        $matches
    );

    return array_map('trim', $matches[1]);
}

it('keeps one menu item lit on every page, however deep', function () {
    $admin = makeSuperAdmin();

    $transfer = app(TransferService::class)->create(
        H::sendingAgent(),
        $this->branch,
        H::transferPayload(),
        (string) Str::uuid(),
    );

    $someUser = User::query()->where('id', '!=', $admin->id)->firstOrFail();

    $pages = [
        route('dashboard') => 'Dashboard',
        route('transfers.create') => 'New Transfer',
        route('transfers.index') => 'All Transfers',
        route('transfers.show', $transfer) => 'All Transfers',
        route('queue.index') => 'Payout Queue',
        route('balance-requests.index') => 'Balance Requests',
        route('balance-requests.inbox') => 'Balance Requests',
        route('balance-requests.create') => 'Balance Requests',
        route('adjustments.index') => 'Balance Requests',
        route('users.index') => 'Users',
        route('users.edit', $someUser) => 'Users',
        route('users.statement', $someUser) => 'Users',
        route('roles.index') => 'Users',
        route('setup.index') => 'Setup',
        route('setup.branches.index') => 'Setup',
        route('setup.branches.create') => 'Setup',
        route('reports.index') => 'Reports',
        route('reports.show', 'transfers') => 'Reports',
        route('audit.index') => 'Audit Log',
        route('company-accounts.index') => 'Company Accounts',
        route('commission.index') => 'Commission',
    ];

    foreach ($pages as $url => $expected) {
        $response = $this->actingAs($admin)->get($url);
        $response->assertOk();

        expect(activeMenuLabels($response->getContent()))
            ->toBe([$expected], "wrong active menu item on {$url}");
    }
});

it('lights nothing on pages that have no menu entry', function () {
    $admin = makeSuperAdmin();

    $response = $this->actingAs($admin)->get(route('profile.edit'));

    expect(activeMenuLabels($response->getContent()))->toBe([]);
});

it('never lights an item the user may not see', function () {
    $payer = H::payoutAgent(Branch::query()->firstOrFail());

    $response = $this->actingAs($payer)->get(route('queue.index'));

    expect(activeMenuLabels($response->getContent()))->toBe(['Payout Queue'])
        ->and($response->getContent())->not->toContain('Audit Log');
});
