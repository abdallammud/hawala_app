<?php

use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Enums\UserType;
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
    $this->mogadishu = H::seedReferenceData();
    $this->hargeisa = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_payout_point' => true, 'is_active' => true]);

    // The super admin is also a payout agent, based in Hargeisa.
    $this->boss = User::factory()->create(['type' => UserType::DualAgent, 'branch_id' => $this->hargeisa->id]);
    $this->boss->assignRole('super-admin');
    H::fund($this->boss, LedgerAccountType::AgentPayout, 500000);
    $this->account = H::payoutAccountFor($this->boss);

    $this->transfer = app(TransferService::class)->create(
        H::sendingAgent(fundCents: 100000), $this->mogadishu, H::transferPayload(), (string) Str::uuid()
    );
});

it('lets an admin claim and pay a transfer for another branch', function () {
    $this->actingAs($this->boss)->post(route('queue.claim', $this->transfer))
        ->assertSessionHasNoErrors();

    expect($this->transfer->fresh()->status)->toBe(TransferStatus::Claimed)
        ->and($this->transfer->fresh()->claimed_by)->toBe($this->boss->id);

    $this->actingAs($this->boss)->post(route('queue.mark-paid', $this->transfer), ['payout_account_id' => $this->account->id])
        ->assertSessionHasNoErrors();

    expect($this->transfer->fresh()->status)->toBe(TransferStatus::Paid);
});

it('shows the claim button on other branches and still offers to assign', function () {
    $this->actingAs($this->boss)->get(route('queue.index'))
        ->assertOk()
        ->assertSee(route('queue.claim', $this->transfer), false)
        ->assertSee(route('queue.force-assign', $this->transfer), false);
});

it('still keeps an ordinary payout agent to their own branch', function () {
    $agent = H::payoutAgent($this->hargeisa);

    $this->actingAs($agent)->post(route('queue.claim', $this->transfer))->assertForbidden();

    expect($this->transfer->fresh()->status)->toBe(TransferStatus::Queued);
});
