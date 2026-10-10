<?php

namespace Database\Seeders;

use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Everything a real installation needs and nothing it doesn't (BUILD.md §15).
 *
 * No branches, no company accounts, no transfers — those are the customer's
 * own data, entered through the Setup screens. Safe to re-run: every step
 * is firstOrCreate and existing settings are left alone.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->baseline();
        $this->superAdmin();
    }

    /** Everything except the login — shared with CustomerDemoSeeder. */
    protected function baseline(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SmsTemplatesSeeder::class,
        ]);

        $this->systemLedgerAccounts();
        $this->currencies();
        $this->accountTypes();
        $this->feeTiers();
        $this->defaultSettings();
    }

    /** One each of the system accounts (BUILD.md §2). */
    private function systemLedgerAccounts(): void
    {
        $ledger = app(LedgerService::class);

        foreach ([
            LedgerAccountType::TransfersPayable,
            LedgerAccountType::FeeIncome,
            LedgerAccountType::FxIncome,
            LedgerAccountType::CommissionExpense,
            LedgerAccountType::BankCharges,
            LedgerAccountType::Adjustments,
            LedgerAccountType::OpeningBalance,
        ] as $type) {
            $ledger->system($type);
        }
    }

    /** BUILD.md §7 — USD, EUR, GBP, SEK, NOK active; DKK, CHF available but off. */
    private function currencies(): void
    {
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
                ['name' => $currency['name'], 'is_active' => $currency['is_active'], 'sort' => $sort],
            );
        }
    }

    private function accountTypes(): void
    {
        $names = ['EVC Plus', 'eDahab', 'Zaad', 'Sahal', 'Premier Bank', 'Salaam Somali Bank', 'Cash'];

        foreach ($names as $sort => $name) {
            AccountType::query()->firstOrCreate(['name' => $name], ['is_active' => true, 'sort' => $sort]);
        }
    }

    /** BUILD.md §5 — 0–999.99 at 5% capped at $25, then 2% uncapped. */
    private function feeTiers(): void
    {
        if (FeeTier::query()->exists()) {
            return;
        }

        FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => 2500, 'sort' => 0]);
        FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
    }

    /**
     * Write the config defaults into the settings table so the Settings
     * screen shows real stored values. Anything already set is left alone —
     * re-running must never undo an admin's choice.
     */
    private function defaultSettings(): void
    {
        foreach (config('hawala') as $group => $values) {
            foreach ($values as $key => $value) {
                $full = "{$group}.{$key}";

                if (! Settings::has($full)) {
                    Settings::set($full, $value);
                }
            }
        }
    }

    /**
     * The one account that can log in on a fresh install. Credentials come
     * from .env so they are never committed.
     */
    private function superAdmin(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'Set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env before running ProductionSeeder.'
            );
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('SUPER_ADMIN_NAME', 'Administrator'),
                'phone' => env('SUPER_ADMIN_PHONE'),
                'type' => UserType::Staff,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('super-admin');
    }
}
