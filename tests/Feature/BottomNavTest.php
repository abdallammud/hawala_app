<?php

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
 * The phone bottom bar as [label => badge count], in left-to-right order,
 * read out of its own <nav> (the sidebar has a different class and lists
 * everything). The raised centre button shows up as "New" in position.
 */
function bottomBar(User $user): array
{
    $html = test()->actingAs($user)->get('/dashboard')->assertOk()->getContent();

    preg_match('/<nav\s+aria-label="Main".*?<\/nav>/s', $html, $nav);

    preg_match_all('/<(?:a|button)\b[^>]*>(.*?)<\/(?:a|button)>/s', $nav[0] ?? '', $items);

    $bar = [];
    foreach ($items[1] as $inner) {
        $spans = preg_match_all('/<span[^>]*>\s*([^<]*?)\s*<\/span>/s', $inner, $m) ? array_values(array_filter($m[1], 'strlen')) : [];
        $label = end($spans);
        $badge = count($spans) > 1 ? $spans[0] : null;
        $bar[$label] = $badge;
    }

    return $bar;
}

it('gives a sending agent Home, Transfers, a raised New, Balance and More', function () {
    expect(array_keys(bottomBar(H::sendingAgent())))
        ->toBe(['Home', 'Transfers', 'New', 'Balance', 'More']);
});

it('gives a payout agent the queue, with no New button they could not use', function () {
    expect(array_keys(bottomBar(H::payoutAgent($this->branch))))
        ->toBe(['Home', 'Queue', 'Balance', 'Commission', 'More']);
});

it('puts a count on the Queue tab for transfers waiting in the branch', function () {
    $sender = H::sendingAgent();
    $payer = H::payoutAgent($this->branch);

    foreach (range(1, 2) as $i) {
        app(TransferService::class)->create($sender, $this->branch, H::transferPayload(), (string) Str::uuid());
    }

    expect(bottomBar($payer)['Queue'])->toBe('2');
});

it('hides the Queue count when nothing is waiting', function () {
    expect(bottomBar(H::payoutAgent($this->branch))['Queue'])->toBeNull();
});

it('gives people who send and pay out the queue and New together', function () {
    $dual = H::sendingAgent();
    $dual->assignRole('sending-payout-agent');
    $dual->update(['branch_id' => $this->branch->id]);

    expect(array_keys(bottomBar($dual->fresh())))
        ->toBe(['Home', 'Queue', 'New', 'Transfers', 'More']);
});

it('never shows more than five slots, whatever the role', function () {
    expect(bottomBar(makeSuperAdmin()))->toHaveCount(5);
});

it('lights the tab for the page you are on', function () {
    $agent = H::sendingAgent();

    $html = $this->actingAs($agent)->get('/transfers')->assertOk()->getContent();
    preg_match('/<nav\s+aria-label="Main".*?<\/nav>/s', $html, $nav);

    expect($nav[0])->toMatch('/aria-current="page"[^>]*>.*?Transfers/s');
});

it('keeps the bar to phones and out of the print view', function () {
    $html = $this->actingAs(H::sendingAgent())->get('/dashboard')->getContent();

    expect($html)->toMatch('/<nav\s+aria-label="Main"[^>]*lg:hidden[^>]*print:hidden/s');
});
