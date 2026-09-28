<?php

namespace Database\Seeders;

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
        User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@bluestar.test',
            'phone' => '+252611000000',
            'password' => bcrypt('password'),
        ]);
    }
}
