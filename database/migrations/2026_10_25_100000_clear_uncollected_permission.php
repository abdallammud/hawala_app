<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * transfers.clear_uncollected: admins can drop the "uncollected" flag from a
 * transfer (e.g. ticked by mistake). A fresh install gets it from
 * RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = Permission::findOrCreate('transfers.clear_uncollected', 'web');

        Role::query()->where('name', 'admin')->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
