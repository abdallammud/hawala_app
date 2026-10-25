<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cities, per country, for the transfer form's City dropdown. Filled with a
 * starting list plus every country/city pair already typed on a customer or
 * transfer, so nothing already in use is lost. A fresh install gets the
 * `setup.cities` permission from RolesAndPermissionsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('country', 100);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country', 'name']);
        });

        $rows = [];

        foreach (require database_path('data/cities.php') as $country => $names) {
            foreach ($names as $name) {
                $rows[] = [$country, $name];
            }
        }

        $used = [
            ['customers', 'country', 'city'],
            ['transfers', 'sender_country', 'sender_city'],
            ['transfers', 'receiver_country', 'receiver_city'],
        ];

        foreach ($used as [$table, $countryCol, $cityCol]) {
            DB::table($table)
                ->whereNotNull($countryCol)->whereNotNull($cityCol)
                ->distinct()->get([$countryCol, $cityCol])
                ->each(function ($r) use (&$rows, $countryCol, $cityCol) {
                    $rows[] = [trim($r->{$countryCol}), trim($r->{$cityCol})];
                });
        }

        $seen = [];
        $now = now();

        foreach ($rows as [$country, $name]) {
            $key = mb_strtolower($country.'|'.$name);

            if ($country === '' || $name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            DB::table('cities')->insert([
                'country' => $country, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('permissions')) {
            $permission = Permission::findOrCreate('setup.cities', 'web');
            Role::query()->where('name', 'admin')->each(fn (Role $role) => $role->givePermissionTo($permission));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
