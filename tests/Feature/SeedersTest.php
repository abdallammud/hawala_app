<?php

use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\SmsTemplate;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(fn () => Notification::fake());

describe('ProductionSeeder', function () {
    it('creates roles, system accounts, reference data and one super admin', function () {
        config(['app.env' => 'production']);
        putenv('SUPER_ADMIN_EMAIL=owner@bluestar.test');
        putenv('SUPER_ADMIN_PASSWORD=a-strong-secret');

        $this->seed(ProductionSeeder::class);

        expect(Role::count())->toBe(4);

        // The six system accounts, one each, with no owner.
        $systemTypes = [
            LedgerAccountType::TransfersPayable, LedgerAccountType::FeeIncome,
            LedgerAccountType::FxIncome, LedgerAccountType::CommissionExpense,
            LedgerAccountType::BankCharges, LedgerAccountType::Adjustments,
        ];

        foreach ($systemTypes as $type) {
            expect(LedgerAccount::where('type', $type->value)->whereNull('owner_id')->count())->toBe(1);
        }

        expect(Currency::count())->toBe(7)
            ->and(AccountType::count())->toBe(7)
            ->and(FeeTier::count())->toBe(2)
            ->and(SmsTemplate::count())->toBe(6)
            // Nothing sends on a fresh install.
            ->and(SmsTemplate::where('is_active', true)->count())->toBe(0)
            ->and(Settings::get('sms.enabled'))->toBeFalse();

        $admin = User::where('email', 'owner@bluestar.test')->first();

        expect($admin)->not->toBeNull()
            ->and($admin->hasRole('super-admin'))->toBeTrue()
            ->and(Hash::check('a-strong-secret', $admin->password))->toBeTrue();

        // A real installation has no branches, company accounts or transfers.
        expect(Branch::count())->toBe(0)
            ->and(CompanyAccount::count())->toBe(0)
            ->and(Transfer::count())->toBe(0);

        putenv('SUPER_ADMIN_EMAIL');
        putenv('SUPER_ADMIN_PASSWORD');
    });

    it('refuses to run without super admin credentials in the environment', function () {
        putenv('SUPER_ADMIN_EMAIL');
        putenv('SUPER_ADMIN_PASSWORD');

        expect(fn () => $this->seed(ProductionSeeder::class))
            ->toThrow(RuntimeException::class);
    });

    it('is safe to run twice and does not overwrite a saved setting', function () {
        putenv('SUPER_ADMIN_EMAIL=owner@bluestar.test');
        putenv('SUPER_ADMIN_PASSWORD=a-strong-secret');

        $this->seed(ProductionSeeder::class);

        // An admin changes something.
        Settings::set('general.company_name', 'Blue Star Hawala');
        Settings::set('payout.claim_minutes', 45);

        $this->seed(ProductionSeeder::class);

        expect(Settings::get('general.company_name'))->toBe('Blue Star Hawala')
            ->and(Settings::get('payout.claim_minutes'))->toBe(45)
            ->and(User::where('email', 'owner@bluestar.test')->count())->toBe(1)
            ->and(FeeTier::count())->toBe(2)
            ->and(Role::count())->toBe(4);

        putenv('SUPER_ADMIN_EMAIL');
        putenv('SUPER_ADMIN_PASSWORD');
    });
});

describe('DemoSeeder', function () {
    beforeEach(function () {
        $this->seed(DemoSeeder::class);
    });

    it('leaves the ledger verifiably correct', function () {
        // Every journal line across the whole dataset sums to zero.
        expect((int) JournalLine::sum('amount_cents'))->toBe(0);

        // And the command BUILD.md §15 requires to pass, passes.
        $this->artisan('hawala:verify-ledger')
            ->expectsOutputToContain('Ledger OK')
            ->assertSuccessful();
    });

    it('creates the people and places from the spec', function () {
        expect(Branch::count())->toBe(4)
            ->and(CompanyAccount::count())->toBe(3)
            ->and(User::where('type', UserType::SendingAgent)->count())->toBe(2)
            ->and(User::where('type', UserType::PayoutAgent)->count())->toBe(3)
            ->and(User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->count())->toBe(1);

        // Every payout agent can actually pay.
        User::where('type', UserType::PayoutAgent)->each(function (User $agent) {
            expect($agent->branch_id)->not->toBeNull()
                ->and($agent->payoutAccounts()->count())->toBe(2);
        });
    });

    it('creates about forty transfers across every status', function () {
        expect(Transfer::count())->toBe(40);

        $byStatus = Transfer::all()->groupBy(fn (Transfer $t) => $t->status->value);

        // Each of these must be represented, or the demo cannot show the flow.
        foreach ([TransferStatus::Paid, TransferStatus::Claimed, TransferStatus::Queued, TransferStatus::Cancelled] as $status) {
            expect($byStatus->get($status->value))->not->toBeNull("no transfer is {$status->value}");
        }

        expect(Transfer::where('is_uncollected', true)->count())->toBeGreaterThan(0);
    });

    it('gives every agent a real balance built from ledger entries', function () {
        User::where('type', UserType::SendingAgent)->each(function (User $agent) {
            $send = LedgerAccount::where('owner_id', $agent->id)
                ->where('type', LedgerAccountType::AgentSend->value)
                ->first();

            expect($send)->not->toBeNull()
                ->and($send->balance_cents)->toBeGreaterThan(0);
        });

        User::where('type', UserType::PayoutAgent)->each(function (User $agent) {
            $payout = LedgerAccount::where('owner_id', $agent->id)
                ->where('type', LedgerAccountType::AgentPayout->value)
                ->first();

            expect($payout)->not->toBeNull();
        });

        // No guarded balance went negative.
        $guarded = [
            LedgerAccountType::AgentSend->value,
            LedgerAccountType::AgentCredit->value,
            LedgerAccountType::AgentCommission->value,
            LedgerAccountType::AgentPayout->value,
        ];

        expect(LedgerAccount::whereIn('type', $guarded)->where('balance_cents', '<', 0)->count())->toBe(0);
    });

    it('lets the demo users actually log in', function () {
        foreach (['admin@bluestar.test', 'ali@bluestar.test', 'farah@bluestar.test'] as $email) {
            $this->post('/login', ['login' => $email, 'password' => 'password'])
                ->assertRedirect(route('dashboard'));

            $this->post('/logout');
        }
    });

    it('is safe to run twice without doubling the data', function () {
        $this->seed(DemoSeeder::class);

        expect(Transfer::count())->toBe(40)
            ->and(Branch::count())->toBe(4)
            ->and(CompanyAccount::count())->toBe(3)
            ->and(User::where('email', 'ali@bluestar.test')->count())->toBe(1)
            ->and((int) JournalLine::sum('amount_cents'))->toBe(0);

        $this->artisan('hawala:verify-ledger')->assertSuccessful();
    });
});
