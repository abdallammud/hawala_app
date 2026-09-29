<?php

namespace Database\Seeders;

use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\FeeTier;
use Illuminate\Database\Seeder;

/**
 * Minimal branches, account types, currencies and fee tiers so later
 * modules (Users, Transfers, quotes) have something to select from and
 * calculate against before their own Setup screens existed.
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

        // BUILD.md §7 — USD, EUR, GBP, SEK, NOK active; DKK, CHF inactive.
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'is_active' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'is_active' => true],
            ['code' => 'GBP', 'name' => 'British Pound', 'is_active' => true],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'is_active' => true],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'is_active' => true],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'is_active' => false],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'is_active' => false],
        ];

        foreach ($currencies as $sort => $currency) {
            Currency::query()->firstOrCreate(
                ['code' => $currency['code']],
                ['name' => $currency['name'], 'is_active' => $currency['is_active'], 'sort' => $sort]
            );
        }

        // BUILD.md §5 — 0–999.99 @ 5% capped at $25, then 2% uncapped.
        if (FeeTier::query()->doesntExist()) {
            FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
            FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
        }
    }
}
