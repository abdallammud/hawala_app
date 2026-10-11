<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `expenses.void` is retired: approvers return an expense to pending instead.
 * Anyone who could void keeps an equivalent: approve + delete. The admin role
 * gets both as well (it holds every permission outside its exclusion list).
 * A fresh install gets all of this from RolesAndPermissionsSeeder instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $approve = Permission::findOrCreate('expenses.approve', 'web');
        $delete = Permission::findOrCreate('expenses.delete', 'web');

        $void = Permission::query()->where('name', 'expenses.void')->where('guard_name', 'web')->first();

        if ($void) {
            Role::query()->whereHas('permissions', fn ($q) => $q->where('permissions.id', $void->id))
                ->each(fn (Role $role) => $role->givePermissionTo($approve, $delete));
            $void->delete();
        }

        Role::query()->where('name', 'admin')->each(fn (Role $role) => $role->givePermissionTo($approve, $delete));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
