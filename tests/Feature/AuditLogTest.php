<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('records who changed what, with the before and after values', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin);

    $branch = Branch::create(['name' => 'Kismayo', 'city' => 'Kismayo', 'is_active' => true]);
    $branch->update(['name' => 'Kismaayo']);

    $updated = Activity::query()
        ->where('subject_type', $branch->getMorphClass())
        ->where('event', 'updated')
        ->latest()
        ->first();

    expect($updated)->not->toBeNull()
        ->and($updated->causer_id)->toBe($admin->id)
        ->and($updated->properties['old']['name'])->toBe('Kismayo')
        ->and($updated->properties['attributes']['name'])->toBe('Kismaayo');
});

it('shows the audit log to a user who may view it', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin);
    Branch::create(['name' => 'Galkayo', 'city' => 'Galkayo', 'is_active' => true]);

    $this->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Galkayo');
});

it('blocks the audit log for a user without the permission', function () {
    $user = User::factory()->create();
    $user->assignRole('sending-agent');

    expect($user->can('audit.view'))->toBeFalse();

    $this->actingAs($user)->get(route('audit.index'))->assertForbidden();
});

it('filters the audit log by user, event and subject type', function () {
    $admin = makeSuperAdmin();
    $other = makeSuperAdmin();

    $this->actingAs($admin);
    $mine = Branch::create(['name' => 'Baidoa', 'city' => 'Baidoa', 'is_active' => true]);

    $this->actingAs($other);
    Branch::create(['name' => 'Berbera', 'city' => 'Berbera', 'is_active' => true]);

    // By causer.
    $this->actingAs($admin)
        ->get(route('audit.index', ['user_id' => $admin->id]))
        ->assertOk()
        ->assertViewHas('activities', fn ($a) => $a->every(fn ($row) => $row->causer_id === $admin->id));

    // By event.
    $this->actingAs($admin)
        ->get(route('audit.index', ['event' => 'updated']))
        ->assertOk()
        ->assertViewHas('activities', fn ($a) => $a->every(fn ($row) => $row->event === 'updated'));

    // By subject type.
    $this->actingAs($admin)
        ->get(route('audit.index', ['subject_type' => $mine->getMorphClass()]))
        ->assertOk()
        ->assertViewHas('activities', fn ($a) => $a->isNotEmpty()
            && $a->every(fn ($row) => $row->subject_type === $mine->getMorphClass()));
});

it('excludes activity outside the date filter', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin);
    Branch::create(['name' => 'Bosaso', 'city' => 'Bosaso', 'is_active' => true]);

    $this->get(route('audit.index', ['from' => '2020-01-01', 'to' => '2020-01-31']))
        ->assertOk()
        ->assertViewHas('activities', fn ($a) => $a->isEmpty());
});
