<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    H::seedReferenceData();
    $this->agent = H::sendingAgent();
});

function addNotification(User $user, string $message, $createdAt = null): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\TransferAssigned',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['message' => $message],
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}

it('requires login to poll', function () {
    $this->getJson(route('notifications.poll'))->assertUnauthorized();
});

it('returns the unread count and no items when there is no cursor', function () {
    addNotification($this->agent, 'Hello');

    $this->actingAs($this->agent)->getJson(route('notifications.poll'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(0, 'items')
        ->assertJsonStructure(['now']);
});

it('returns only unread notifications newer than the cursor', function () {
    addNotification($this->agent, 'Old one', now()->subHour());
    $new = addNotification($this->agent, 'New one', now());
    addNotification($this->agent, 'Already read')->markAsRead();
    addNotification(User::factory()->create(), "Someone else's");

    $this->actingAs($this->agent)
        ->getJson(route('notifications.poll', ['after' => now()->subMinute()->toIso8601String()]))
        ->assertOk()
        ->assertJsonPath('unread', 2)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $new->id)
        ->assertJsonPath('items.0.message', 'New one');
});

it('ignores an invalid cursor', function () {
    addNotification($this->agent, 'Hello');

    $this->actingAs($this->agent)->getJson(route('notifications.poll', ['after' => 'garbage']))
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

it('renders the bell wired to the poll endpoint', function () {
    $this->actingAs($this->agent)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('notifications.poll'), false);
});
