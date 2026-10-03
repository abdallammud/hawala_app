<?php

namespace Database\Seeders;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

/**
 * Setup for the customer demo server. A clean install (ProductionSeeder's
 * baseline) plus just enough to try every form: one payout branch, two
 * company accounts, and two super admins who also act as agents so one can
 * request and the other approve (maker ≠ checker).
 *
 * Agents start at $0 on purpose — the demo begins with a top-up. Safe to
 * re-run: nothing is duplicated and opening capital is posted only once.
 *
 * Password comes from SUPER_ADMIN_PASSWORD in .env so it is never committed.
 */
class CustomerDemoSeeder extends ProductionSeeder
{
    public function run(): void
    {
        $password = env('SUPER_ADMIN_PASSWORD');

        if (blank($password)) {
            throw new RuntimeException('Set SUPER_ADMIN_PASSWORD in .env before running CustomerDemoSeeder.');
        }

        $this->baseline();

        $mogadishu = Branch::query()->firstOrCreate(
            ['name' => 'Mogadishu (Banadir)'],
            ['city' => 'Mogadishu', 'is_active' => true],
        );

        $brusselsOffice = CompanyAccount::query()->firstOrCreate(
            ['name' => 'Brussels Office'],
            ['location' => 'europe', 'is_active' => true],
        );

        $mogadishuOffice = CompanyAccount::query()->firstOrCreate(
            ['name' => 'Mogadishu Office'],
            ['location' => 'somalia', 'is_active' => true],
        );

        $omar = $this->superAdmin('omar@bluestar.so', 'Omar Muse', UserType::SendingAgent, null, $password);
        $yusuf = $this->superAdmin('yusuf@bluestar.so', 'Yusuf Ali', UserType::PayoutAgent, $mogadishu, $password);

        $omar->update(['max_credit_cents' => 500000]); // $5,000

        // Yusuf needs accounts to pay receivers from; numbers are placeholders.
        foreach (['EVC Plus' => '610000001', 'eDahab' => '620000001', 'Cash' => 'CASH'] as $typeName => $number) {
            $type = AccountType::query()->where('name', $typeName)->first();

            if ($type) {
                PayoutAccount::query()->firstOrCreate(
                    ['user_id' => $yusuf->id, 'account_type_id' => $type->id],
                    ['number' => $number, 'is_active' => true],
                );
            }
        }

        // Mogadishu holds the cash that funds payout agents. Brussels starts
        // empty and fills from sending agents' paid top-ups.
        $this->openingCapital($mogadishuOffice, 2000000, $yusuf); // $20,000

        $this->fetchRates();

        $this->command?->info('Customer demo ready: omar@bluestar.so and yusuf@bluestar.so.');
    }

    private function superAdmin(string $email, string $name, UserType $type, ?Branch $branch, string $password): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'type' => $type,
                'branch_id' => $branch?->id,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles(['super-admin']);

        return $user;
    }

    private function openingCapital(CompanyAccount $account, int $cents, User $by): void
    {
        $ledger = app(LedgerService::class);
        $ledgerAccount = $ledger->accountFor($account, LedgerAccountType::Company);

        if ($ledgerAccount->lines()->exists()) {
            return;
        }

        $ledger->post(
            JournalType::ManualAdjustment,
            [[$ledgerAccount, $cents], [$ledger->system(LedgerAccountType::Adjustments), -$cents]],
            null,
            $by,
            null,
            'Opening balance',
        );
    }

    /** Live rates so EUR/GBP/SEK/NOK quotes work immediately; the hourly cron keeps them fresh. */
    private function fetchRates(): void
    {
        try {
            Artisan::call('hawala:fetch-rates');
            $this->command?->line(trim(Artisan::output()));
        } catch (Throwable $e) {
            $this->command?->warn('Could not fetch exchange rates: '.$e->getMessage().' — only USD will quote until the cron runs.');
        }
    }
}
