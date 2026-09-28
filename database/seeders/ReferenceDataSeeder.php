<?php

namespace Database\Seeders;

use App\Models\AccountType;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Minimal branches and account types so the Users module (payout agent
 * branch assignment, payout accounts) has something to select from before
 * their own Setup screens are built.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(['name' => 'Mogadishu'], ['city' => 'Mogadishu', 'is_active' => true]);
        Branch::query()->firstOrCreate(['name' => 'Hargeisa'], ['city' => 'Hargeisa', 'is_active' => true]);

        $accountTypes = ['EVC Plus', 'eDahab', 'Zaad', 'Sahal', 'Premier Bank', 'Salaam Somali Bank', 'Cash'];

        foreach ($accountTypes as $sort => $name) {
            AccountType::query()->firstOrCreate(['name' => $name], ['is_active' => true, 'sort' => $sort]);
        }
    }
}
