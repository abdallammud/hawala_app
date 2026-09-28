<?php

use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReferenceDataSeeder::class);
});

it('blocks admins without setup.roles from the roles screen', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/users/roles')->assertForbidden();
});

it('lets a super-admin list roles', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->get('/users/roles')->assertOk()->assertSee('sending-agent');
});

it('creates a role with selected permissions', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->post('/users/roles', [
        'name' => 'branch-supervisor',
        'permissions' => ['transfers.view_branch', 'queue.view', 'reports.branch_summary'],
    ])->assertRedirect('/users/roles');

    $role = Role::query()->where('name', 'branch-supervisor')->firstOrFail();

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['queue.view', 'reports.branch_summary', 'transfers.view_branch']);
});

it('rejects an invalid role name', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->post('/users/roles', [
        'name' => 'Branch Supervisor!',
        'permissions' => [],
    ])->assertSessionHasErrors('name');
});

it('updates a role permissions and can shrink them', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $role = Role::create(['name' => 'custom-role', 'guard_name' => 'web']);
    $role->syncPermissions(['dashboard.view', 'reports.transfers']);

    $this->actingAs($superAdmin)->put("/users/roles/{$role->id}", [
        'name' => 'custom-role',
        'permissions' => ['dashboard.view'],
    ])->assertRedirect('/users/roles');

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['dashboard.view']);
});

it('cannot delete a role that is assigned to a user', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $role = Role::create(['name' => 'custom-role', 'guard_name' => 'web']);
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('custom-role');

    $this->actingAs($superAdmin)->delete("/users/roles/{$role->id}")->assertRedirect();

    expect(Role::query()->where('name', 'custom-role')->exists())->toBeTrue();
});

it('deletes an unused role', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $role = Role::create(['name' => 'custom-role', 'guard_name' => 'web']);

    $this->actingAs($superAdmin)->delete("/users/roles/{$role->id}")->assertRedirect('/users/roles');

    expect(Role::query()->where('name', 'custom-role')->exists())->toBeFalse();
});

it('protects the super-admin role from being edited or deleted', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $protected = Role::query()->where('name', 'super-admin')->firstOrFail();

    $this->actingAs($superAdmin)->put("/users/roles/{$protected->id}", [
        'name' => 'super-admin',
        'permissions' => [],
    ])->assertForbidden();

    $this->actingAs($superAdmin)->delete("/users/roles/{$protected->id}")->assertForbidden();
});

it('lets a newly created role actually gate access once assigned', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $this->actingAs($superAdmin)->post('/users/roles', [
        'name' => 'reports-only',
        'permissions' => ['dashboard.view', 'reports.transfers'],
    ]);

    $user = User::factory()->create(['type' => UserType::Staff]);
    $user->assignRole('reports-only');

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/users')->assertForbidden();
});
