<?php

namespace Tests\Feature\Transfers;

use App\Enums\CommissionType;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\FeeTier;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Str;

/**
 * Shared scaffolding for the Phase 5 transfer tests: a funded sending
 * agent, a funded payout agent, and the reference data a transfer needs.
 */
class TransferTestHelpers
{
    public static function seedReferenceData(): Branch
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true, 'manual_rate' => '1.08000000']);

        if (FeeTier::query()->doesntExist()) {
            FeeTier::create(['from_cents' => 0, 'to_cents' => 100000, 'rate_bp' => 500, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 0]);
            FeeTier::create(['from_cents' => 100000, 'to_cents' => null, 'rate_bp' => 200, 'flat_cents' => 0, 'cap_cents' => null, 'sort' => 1]);
        }

        AccountType::query()->firstOrCreate(['name' => 'EVC Plus'], ['is_active' => true, 'sort' => 0]);

        return Branch::query()->firstOrCreate(['name' => 'Mogadishu'], ['city' => 'Mogadishu', 'is_active' => true]);
    }

    /** A sending agent with 50% send commission, funded with $sendBalance. */
    public static function sendingAgent(int $fundCents = 500000): User
    {
        $agent = User::factory()->create([
            'type' => UserType::SendingAgent,
            'send_commission_type' => CommissionType::Percent,
            'send_commission_value' => 5000,
        ]);
        $agent->assignRole('sending-agent');

        if ($fundCents > 0) {
            self::fund($agent, LedgerAccountType::AgentSend, $fundCents);
        }

        return $agent;
    }

    /** A payout agent in $branch with 20% payout commission, funded with $payoutBalance. */
    public static function payoutAgent(Branch $branch, int $fundCents = 500000): User
    {
        $agent = User::factory()->create([
            'type' => UserType::PayoutAgent,
            'branch_id' => $branch->id,
            'payout_commission_type' => CommissionType::Percent,
            'payout_commission_value' => 2000,
        ]);
        $agent->assignRole('payout-agent');

        if ($fundCents > 0) {
            self::fund($agent, LedgerAccountType::AgentPayout, $fundCents);
        }

        return $agent;
    }

    public static function payoutAccountFor(User $agent): PayoutAccount
    {
        return $agent->payoutAccounts()->create([
            'account_type_id' => AccountType::query()->firstOrFail()->id,
            'number' => '61'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);
    }

    /** Seed a balance straight through the ledger so guards stay honest. */
    public static function fund(User $user, LedgerAccountType $type, int $cents): void
    {
        $ledger = app(LedgerService::class);
        $funder = User::factory()->create();

        $company = CompanyAccount::create([
            'name' => 'Funding source '.Str::random(6),
            'location' => 'europe',
            'is_active' => true,
        ]);

        $companyLedger = $ledger->accountFor($company, LedgerAccountType::Company);
        $adjustments = $ledger->system(LedgerAccountType::Adjustments);

        // Give the company account the money first, then move it to the user.
        $ledger->post(JournalType::ManualAdjustment, [[$companyLedger, $cents], [$adjustments, -$cents]], null, $funder);

        $target = $ledger->accountFor($user, $type);

        $lines = $type === LedgerAccountType::AgentSend
            ? [[$companyLedger, $cents], [$target, -$cents]]   // credit-normal: negative raises it
            : [[$target, $cents], [$companyLedger, -$cents]];  // debit-normal: positive raises it

        $journalType = $type === LedgerAccountType::AgentSend ? JournalType::TopupPaid : JournalType::PayoutFunding;

        $ledger->post($journalType, $lines, null, $funder);
    }

    public static function transferPayload(array $overrides = []): array
    {
        return array_merge([
            'sender_name' => 'Ali Hassan',
            'sender_phone' => '+46701234567',
            'receiver_name' => 'Farah Omar',
            'receiver_phone' => '+252611234567',
            'receiver_account_type_id' => null,
            'receiver_account_number' => null,
            'currency' => 'USD',
            'amount_cents' => 10000,
            'is_uncollected' => false,
            'note' => null,
        ], $overrides);
    }
}
