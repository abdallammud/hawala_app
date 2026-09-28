<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Local-only demo login for reviewing the UI shell. Replaced by
     * ProductionSeeder / DemoSeeder in Phase 7.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@bluestar.test'],
            [
                'name' => 'Demo Admin',
                'phone' => '+252611000000',
                'type' => UserType::Staff,
                'password' => bcrypt('password'),
            ]
        );

        $admin->assignRole('super-admin');
    }
}
