<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * setup.email_settings: the Gmail address and app password (super-admin only,
 * like Settings). setup.email: edit the email texts and read the email log
 * (admin, like the SMS templates). A fresh install gets both from
 * RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::findOrCreate('setup.email_settings', 'web');
        $templates = Permission::findOrCreate('setup.email', 'web');

        Role::query()->where('name', 'admin')->each(fn (Role $role) => $role->givePermissionTo($templates));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
