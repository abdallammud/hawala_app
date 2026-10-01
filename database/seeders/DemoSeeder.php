<?php

namespace Database\Seeders;

use App\Enums\CommissionType;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\QuoteMode;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PayoutAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A believable demo dataset (BUILD.md §15). Local only.
 *
 * Every balance is created with a real ledger entry and every transfer goes
 * through TransferService / PayoutQueueService rather than being inserted
 * directly, so `hawala:verify-ledger` passes afterwards and the demo
 * exercises the same code paths production does.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            SmsTemplatesSeeder::class,
        ]);

        // SMS off during seeding: a demo must not try to text anybody.
        Settings::set('sms.enabled', false);

        $this->referenceData();

        $branches = [
            'Mogadishu' => Branch::query()->firstOrCreate(['name' => 'Mogadishu'], ['city' => 'Mogadishu', 'is_active' => true]),
            'Hargeisa' => Branch::query()->firstOrCreate(['name' => 'Hargeisa'], ['city' => 'Hargeisa', 'is_active' => true]),
        ];

        $companyAccounts = $this->companyAccounts();
        $admin = $this->admin();
        $senders = $this->sendingAgents();
        $payers = $this->payoutAgents($branches);

        $this->fundEverybody($companyAccounts, $senders, $payers, $admin);
        $this->transfers($senders, $payers, $branches, $admin);

        $this->command?->info('Demo data ready. Log in as admin@bluestar.test / '.self::PASSWORD);
    }

    private function referenceData(): void
    {
        $this->callSilent(ReferenceDataSeeder::class);

        $ledger = app(LedgerService::class);

        foreach ([
            LedgerAccountType::TransfersPayable,
            LedgerAccountType::FeeIncome,
            LedgerAccountType::FxIncome,
            LedgerAccountType::CommissionExpense,
            LedgerAccountType::BankCharges,
            LedgerAccountType::Adjustments,
        ] as $type) {
            $ledger->system($type);
        }
    }

    /** @return array<string, CompanyAccount> */
    private function companyAccounts(): array
    {
        $accounts = [
            'europe' => ['name' => 'Europe Office Cash', 'location' => 'europe'],
            'bank' => ['name' => 'Mogadishu Bank', 'location' => 'somalia'],
            'evc' => ['name' => 'Company EVC', 'location' => 'somalia'],
        ];

        $created = [];

        foreach ($accounts as $key => $attributes) {
            $created[$key] = CompanyAccount::query()->firstOrCreate(
                ['name' => $attributes['name']],
                ['location' => $attributes['location'], 'is_active' => true],
            );
        }

        return $created;
    }

    private function admin(): User
    {
        $admin = $this->user('admin@bluestar.test', 'Demo Admin', '+252611000000', UserType::Staff);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    /** @return User[] */
    private function sendingAgents(): array
    {
        $agents = [
            ['ali@bluestar.test', 'Ali Hassan', '+447700900001', 'London'],
            ['sara@bluestar.test', 'Sara Yusuf', '+46701234002', 'Stockholm'],
        ];

        return collect($agents)->map(function (array $a) {
            $user = $this->user($a[0], $a[1], $a[2], UserType::SendingAgent);

            $user->update([
                'send_commission_type' => CommissionType::Percent,
                'send_commission_value' => 5000, // 50% of the fee
                'max_credit_cents' => 200000,
            ]);

            $user->syncRoles(['sending-agent']);

            return $user;
        })->all();
    }

    /** @return User[] */
    private function payoutAgents(array $branches): array
    {
        $agents = [
            ['farah@bluestar.test', 'Farah Omar', '+252611234003', 'Mogadishu'],
            ['hodan@bluestar.test', 'Hodan Ali', '+252611234004', 'Mogadishu'],
            ['idil@bluestar.test', 'Idil Jama', '+252631234005', 'Hargeisa'],
        ];

        return collect($agents)->map(function (array $a) use ($branches) {
            $user = $this->user($a[0], $a[1], $a[2], UserType::PayoutAgent);

            $user->update([
                'branch_id' => $branches[$a[3]]->id,
                'payout_commission_type' => CommissionType::Percent,
                'payout_commission_value' => 2000, // 20% of what's left
            ]);

            $user->syncRoles(['payout-agent']);

            // Two ways to pay out each.
            foreach (['EVC Plus', 'eDahab'] as $i => $typeName) {
                $type = AccountType::query()->where('name', $typeName)->first();

                if ($type) {
                    PayoutAccount::query()->firstOrCreate(
                        ['user_id' => $user->id, 'account_type_id' => $type->id],
                        ['number' => '61'.str_pad((string) ($user->id * 10 + $i), 7, '0', STR_PAD_LEFT), 'is_active' => true],
                    );
                }
            }

            return $user;
        })->all();
    }

    private function user(string $email, string $name, string $phone, UserType $type): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'type' => $type,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // firstOrCreate skips the password on an existing row, which made the
        // demo login fail after earlier manual testing.
        $user->forceFill(['password' => Hash::make(self::PASSWORD), 'is_active' => true])->save();

        return $user;
    }

    /**
     * Opening balances, posted properly: money enters through the adjustments
     * account into a company account, then moves out to the agents.
     */
    private function fundEverybody(array $company, array $senders, array $payers, User $admin): void
    {
        $ledger = app(LedgerService::class);

        $europe = $ledger->accountFor($company['europe'], LedgerAccountType::Company);
        $bank = $ledger->accountFor($company['bank'], LedgerAccountType::Company);
        $evc = $ledger->accountFor($company['evc'], LedgerAccountType::Company);
        $adjustments = $ledger->system(LedgerAccountType::Adjustments);

        // Opening capital.
        $this->openingBalance($ledger, $europe, $adjustments, 2000000, $admin);  // $20,000
        $this->openingBalance($ledger, $bank, $adjustments, 1500000, $admin);    // $15,000
        $this->openingBalance($ledger, $evc, $adjustments, 500000, $admin);      // $5,000

        // Sending agents pay cash in, raising their send balance.
        foreach ($senders as $agent) {
            $send = $ledger->accountFor($agent, LedgerAccountType::AgentSend);

            $ledger->post(
                JournalType::TopupPaid,
                [[$europe, 800000], [$send, -800000]], // $8,000 each
                null,
                $admin,
            );
        }

        // Payout agents are funded from the Somali accounts.
        foreach ($payers as $i => $agent) {
            $payout = $ledger->accountFor($agent, LedgerAccountType::AgentPayout);
            $source = $i === 2 ? $evc : $bank;

            $ledger->post(
                JournalType::PayoutFunding,
                [[$payout, 400000], [$source, -400000]], // $4,000 each
                null,
                $admin,
            );
        }
    }

    private function openingBalance(LedgerService $ledger, $account, $adjustments, int $cents, User $admin): void
    {
        // Idempotent: don't stack opening balances on a re-run.
        if ($account->fresh()->balance_cents > 0) {
            return;
        }

        $ledger->post(
            JournalType::ManualAdjustment,
            [[$account, $cents], [$adjustments, -$cents]],
            null,
            $admin,
            null,
            'Opening balance',
        );
    }

    /**
     * ~40 transfers spread across every status, plus a few uncollected ones.
     * All through the real services, so the ledger stays consistent.
     */
    private function transfers(array $senders, array $payers, array $branches, User $admin): void
    {
        if (Transfer::query()->count() >= 40) {
            return;
        }

        $transfers = app(TransferService::class);
        $queue = app(PayoutQueueService::class);
        $cancellations = app(TransferCancellationService::class);

        $receivers = [
            ['Amina Abdi', '+252612345101'], ['Mohamed Yusuf', '+252612345102'],
            ['Khadija Nur', '+252612345103'], ['Abdi Warsame', '+252612345104'],
            ['Fatima Omar', '+252612345105'], ['Hassan Jama', '+252612345106'],
            ['Layla Farah', '+252612345107'], ['Omar Said', '+252612345108'],
        ];

        $senderNames = ['Yasin Ahmed', 'Deqa Hussein', 'Bashir Ali', 'Muna Abdullahi'];
        $evcPlus = AccountType::query()->where('name', 'EVC Plus')->first();

        // A euro rate and a margin, so the demo has a real FX leg: agents in
        // Europe mostly hold euros, and both quoting directions appear below.
        $this->seedEuroRate();

        for ($i = 0; $i < 40; $i++) {
            $agent = $senders[$i % count($senders)];
            $branchName = $i % 4 === 3 ? 'Hargeisa' : 'Mogadishu';
            $branch = $branches[$branchName];
            $receiver = $receivers[$i % count($receivers)];

            // $40 to $520, in round amounts an agent would actually type.
            $amountCents = (4000 + ($i % 13) * 4000);

            // Every third one is paid for in euros: half of those are "here is
            // €X" (pay), half are "he must receive $Y" (receive).
            $inEuros = $i % 3 === 1;
            $mode = ($inEuros && $i % 6 === 1) ? QuoteMode::Pay : QuoteMode::Receive;

            // Every fifth one is left uncollected at the counter.
            $isUncollected = $i % 9 === 4;

            $transfer = $transfers->create($agent, $branch, [
                'sender_name' => $senderNames[$i % count($senderNames)],
                'sender_phone' => '+4670123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'receiver_name' => $receiver[0],
                'receiver_phone' => $receiver[1],
                'receiver_account_type_id' => $i % 3 === 0 ? $evcPlus?->id : null,
                'receiver_account_number' => $i % 3 === 0 ? '61'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT) : null,
                'currency' => $inEuros ? 'EUR' : 'USD',
                'mode' => $mode,
                'amount_cents' => $mode === QuoteMode::Pay ? intdiv($amountCents * 100, 108) : $amountCents,
                'is_uncollected' => $isUncollected,
                'note' => null,
            ], (string) Str::uuid());

            // Spread them over the last three weeks so reports have a shape.
            $createdAt = now()->subDays(21 - intdiv($i * 21, 40))->setTime(9 + ($i % 8), ($i * 7) % 60);
            $transfer->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            $this->advance($transfer, $i, $payers, $branchName, $queue, $cancellations, $admin);
        }
    }

    /** One manual EUR rate and a 1.5% margin — enough for the FX reports. */
    private function seedEuroRate(): void
    {
        Currency::query()->where('code', 'EUR')->update(['is_active' => true, 'manual_rate' => '1.08000000']);

        ExchangeRate::query()->firstOrCreate(
            ['currency' => 'EUR', 'source' => 'demo'],
            ['rate_to_usd' => '1.08000000', 'fetched_at' => now()],
        );

        Settings::set('currency.margin_bp', 150);
    }

    /** Move a transfer into one of the statuses a real queue contains. */
    private function advance(
        Transfer $transfer,
        int $i,
        array $payers,
        string $branchName,
        PayoutQueueService $queue,
        TransferCancellationService $cancellations,
        User $admin,
    ): void {
        $branchPayers = collect($payers)->filter(
            fn (User $p) => $p->branch?->name === $branchName
        )->values();

        if ($branchPayers->isEmpty()) {
            return;
        }

        $payer = $branchPayers[$i % $branchPayers->count()];

        // Roughly: 60% paid, 15% claimed, 15% queued, 10% cancelled.
        $bucket = $i % 20;

        if ($bucket < 12) {
            $queue->claim($transfer, $payer);
            $account = $payer->payoutAccounts()->first();

            if ($account) {
                $paid = $queue->markPaid($transfer->fresh(), $payer, $account, 'REF'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
                $paid->forceFill(['paid_at' => $transfer->created_at->copy()->addHours(2)])->save();
            }

            return;
        }

        if ($bucket < 15) {
            $queue->claim($transfer, $payer);

            return;
        }

        if ($bucket < 18) {
            return; // left queued
        }

        $cancellations->cancel($transfer, $admin, 'Customer changed their mind');
    }
}
