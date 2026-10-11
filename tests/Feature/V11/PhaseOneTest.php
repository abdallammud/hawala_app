<?php

use App\Enums\TransferStatus;
use App\Enums\UserType;
use App\Exceptions\Transfers\TransferStateException;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
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

function v11Office(string $name = 'London'): Branch
{
    return Branch::create(['name' => $name, 'city' => $name, 'is_payout_point' => false, 'is_active' => true]);
}

function v11PaidTransfer(Branch $branch): array
{
    $agent = H::sendingAgent(fundCents: 100000);
    $payer = H::payoutAgent($branch);
    $account = H::payoutAccountFor($payer);
    $queue = app(PayoutQueueService::class);

    $transfer = app(TransferService::class)->create($agent, $branch, H::transferPayload(), (string) Str::uuid());
    $transfer = $queue->markPaid($queue->claim($transfer, $payer), $payer, $account);

    return [$transfer, $agent];
}

// --- Item 7: tracking number ---------------------------------------------

// --- Item 10: branches ---------------------------------------------------

it('stamps the sending agent’s own branch on the transfer', function () {
    $london = v11Office();
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->update(['branch_id' => $london->id]);

    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    expect($transfer->branch_id)->toBe($this->branch->id)
        ->and($transfer->origin_branch_id)->toBe($london->id);
});

it('offers only payout points as a destination', function () {
    v11Office('Stockholm');
    $agent = H::sendingAgent();

    $this->actingAs($agent)->get('/transfers/create')->assertOk()->assertDontSee('Stockholm');

    $this->actingAs($agent)->post('/transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => Branch::where('name', 'Stockholm')->first()->id,
        'sender_name' => 'A', 'sender_phone' => '1', 'receiver_name' => 'B', 'receiver_phone' => '2',
        'currency' => 'USD', 'payout_currency' => 'USD', 'mode' => 'receive', 'payout_amount' => '10',
        'confirm_duplicate' => '1',
    ])->assertSessionHasErrors('branch_id');

    expect(Transfer::count())->toBe(0);
});

it('requires a branch on every user, whatever the type', function () {
    $admin = makeAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->put("/users/{$target->id}", [
        'name' => $target->name, 'email' => $target->email, 'type' => 'staff', 'role' => 'sending-agent',
    ])->assertSessionHasErrors('branch_id');
});

it('lets a branch-scoped user see transfers sent from or paid at their branch', function () {
    $london = v11Office();
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->update(['branch_id' => $london->id]);
    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    $manager = User::factory()->create(['branch_id' => $london->id]);
    $manager->givePermissionTo('transfers.view_branch');

    $stranger = User::factory()->create(['branch_id' => v11Office('Oslo')->id]);
    $stranger->givePermissionTo('transfers.view_branch');

    expect(Transfer::visibleTo($manager)->pluck('id')->all())->toBe([$transfer->id])
        ->and(Transfer::visibleTo($stranger)->count())->toBe(0);
});

// --- Item 11: a paid transfer cannot be cancelled -------------------------

it('refuses every way of cancelling a paid transfer', function () {
    [$transfer, $agent] = v11PaidTransfer($this->branch);
    $admin = makeAdmin();

    expect($transfer->status)->toBe(TransferStatus::Paid);

    expect(fn () => app(TransferCancellationService::class)->cancel($transfer, $admin, 'too late'))
        ->toThrow(TransferStateException::class);

    $this->actingAs($admin)->post("/transfers/{$transfer->id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($agent)->post("/transfers/{$transfer->id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($agent)->post("/transfers/{$transfer->id}/request-cancel", ['reason' => 'x'])->assertForbidden();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Paid);
});

it('shows no cancel button on a paid transfer', function () {
    [$transfer] = v11PaidTransfer($this->branch);

    $this->actingAs(makeAdmin())->get("/transfers/{$transfer->id}")
        ->assertOk()
        ->assertDontSee("transfers/{$transfer->id}/cancel")
        ->assertDontSee('request-cancel');
});

// --- Item 12: dashboard cards link to their reports ------------------------

it('links the sending agent’s cards to their reports', function () {
    $agent = H::sendingAgent();

    $html = $this->actingAs($agent)->get('/dashboard')->assertOk()->getContent();

    expect($html)->toContain('/reports/commissions')
        ->and($html)->toContain('/reports/uncollected')
        ->and($html)->toContain('/reports/agent-statement');
});

it('links the admin cards to reports they may open', function () {
    $html = $this->actingAs(makeAdmin())->get('/dashboard')->assertOk()->getContent();

    expect($html)->toContain('/reports/company-accounts')
        ->and($html)->toContain('/reports/credit')
        ->and($html)->toContain('/reports/profit');
});

it('leaves a card as plain text when the user may not open its report', function () {
    // No role, so no report permissions at all.
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->givePermissionTo(['dashboard.view', 'transfers.create']);

    $html = $this->actingAs($agent)->get('/dashboard')->assertOk()->getContent();

    expect($html)->not->toContain('/reports/commissions');
});
