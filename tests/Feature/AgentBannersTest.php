<?php

use App\Enums\UserType;
use App\Models\Branch;
use App\Models\DestinationAnnouncement;
use App\Services\Banners\DestinationAnnouncer;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    H::seedReferenceData();
    $this->nairobi = Branch::create(['name' => 'Nairobi Hub', 'city' => 'Nairobi', 'is_payout_point' => true, 'is_active' => true]);
});

function newUserPayload(Branch $branch, array $overrides = []): array
{
    return [
        'name' => 'New Payer',
        'email' => 'payer'.uniqid().'@example.com',
        'password' => 'Sup3r-secret-pass!',
        'password_confirmation' => 'Sup3r-secret-pass!',
        'type' => UserType::PayoutAgent->value,
        'branch_id' => $branch->id,
        'role' => 'payout-agent',
        ...$overrides,
    ];
}

it('shows sending agents a reminder that links to all transfers', function () {
    $agent = H::sendingAgent();

    $this->actingAs($agent)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('marquee', false)
        ->assertSee('please check your transfers')
        ->assertSee('href="'.route('transfers.index').'"', false);
});

it('does not show either banner to payout agents or admins', function () {
    DestinationAnnouncement::create(['branch_id' => $this->nairobi->id]);

    foreach ([H::payoutAgent($this->nairobi), makeAdmin()] as $user) {
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('class="marquee', false);
    }
});

it('announces a place the first time a payout agent is created there', function () {
    $this->actingAs(makeAdmin())
        ->post(route('users.store'), newUserPayload($this->nairobi))
        ->assertRedirect(route('users.index'));

    expect(DestinationAnnouncement::where('branch_id', $this->nairobi->id)->count())->toBe(1);

    $this->actingAs(H::sendingAgent())->get(route('dashboard'))
        ->assertSee('You can now send money to Nairobi.');
});

it('says nothing about a second agent in a place that already has one', function () {
    H::payoutAgent($this->nairobi);

    $this->actingAs(makeAdmin())->post(route('users.store'), newUserPayload($this->nairobi));

    expect(DestinationAnnouncement::count())->toBe(0);
});

it('does not announce sending agents, inactive agents or non-payout branches', function () {
    $admin = makeAdmin();
    $office = Branch::create(['name' => 'Head Office', 'city' => 'Hamburg', 'is_payout_point' => false, 'is_active' => true]);

    $this->actingAs($admin)->post(route('users.store'), newUserPayload($this->nairobi, ['type' => UserType::SendingAgent->value, 'role' => 'sending-agent']));
    $this->actingAs($admin)->post(route('users.store'), newUserPayload($office));

    expect(DestinationAnnouncement::count())->toBe(0);
});

it('announces when an agent is moved to an empty place or reactivated there', function () {
    $admin = makeAdmin();
    $agent = H::payoutAgent(Branch::query()->where('name', 'Mogadishu')->first());

    $this->actingAs($admin)->put(route('users.update', $agent), [
        'name' => $agent->name,
        'email' => $agent->email,
        'type' => UserType::PayoutAgent->value,
        'branch_id' => $this->nairobi->id,
        'role' => 'payout-agent',
    ])->assertSessionHasNoErrors();

    expect(DestinationAnnouncement::where('branch_id', $this->nairobi->id)->exists())->toBeTrue();
});

it('stops showing the place after the window, or once it is no longer a destination', function () {
    $announcement = DestinationAnnouncement::create(['branch_id' => $this->nairobi->id]);
    $announcer = app(DestinationAnnouncer::class);

    expect($announcer->placesToShow()->all())->toBe(['Nairobi']);

    $this->nairobi->update(['is_active' => false]);
    expect($announcer->placesToShow())->toBeEmpty();

    $this->nairobi->update(['is_active' => true]);
    $announcement->forceFill(['created_at' => now()->subDays(8)])->save();
    expect($announcer->placesToShow())->toBeEmpty();

    Settings::set('banners.new_destination_days', 14);
    expect($announcer->placesToShow()->all())->toBe(['Nairobi']);
});

it('lets an admin switch each banner off', function () {
    DestinationAnnouncement::create(['branch_id' => $this->nairobi->id]);
    Settings::set('banners.transfer_reminder', false);
    Settings::set('banners.new_destination', false);

    $this->actingAs(H::sendingAgent())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('please check your transfers')
        ->assertDontSee('You can now send money to');
});
