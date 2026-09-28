<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Ledger\LedgerService;

/**
 * The canonical scenario from BUILD.md §4. Numbers are asserted exactly as
 * documented there.
 */
it('runs the full Ali and Farah scenario with exact balances', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();

    $ali = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_value' => 5000]); // 50%
    $farah = User::factory()->create(['type' => UserType::PayoutAgent, 'payout_commission_value' => 2000]); // 20%

    $europeOffice = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);
    $mogadishuBank = CompanyAccount::create(['name' => 'Mogadishu bank', 'location' => 'somalia', 'is_active' => true]);

    $europeLedger = $service->accountFor($europeOffice, LedgerAccountType::Company);
    $mogadishuLedger = $service->accountFor($mogadishuBank, LedgerAccountType::Company);
    $adjustments = $service->system(LedgerAccountType::Adjustments);
    $aliSend = $service->accountFor($ali, LedgerAccountType::AgentSend);
    $aliCommission = $service->accountFor($ali, LedgerAccountType::AgentCommission);
    $aliCredit = $service->accountFor($ali, LedgerAccountType::AgentCredit);
    $farahPayout = $service->accountFor($farah, LedgerAccountType::AgentPayout);
    $farahCommission = $service->accountFor($farah, LedgerAccountType::AgentCommission);
    $transfersPayable = $service->system(LedgerAccountType::TransfersPayable);
    $feeIncome = $service->system(LedgerAccountType::FeeIncome);
    $commissionExpense = $service->system(LedgerAccountType::CommissionExpense);

    // 1. Seed Mogadishu bank with $5,000 (Dr Mogadishu bank / Cr adjustments).
    $service->post(JournalType::ManualAdjustment, [
        [$mogadishuLedger, 5000_00],
        [$adjustments, -5000_00],
    ], null, $admin, $admin, 'Opening balance');

    // 2. Ali tops up $1,000 into Europe office.
    $service->post(JournalType::TopupPaid, [
        [$europeLedger, 1000_00],
        [$aliSend, -1000_00],
    ], null, $admin);

    // 3. Farah is funded $1,000 from Mogadishu bank.
    $service->post(JournalType::PayoutFunding, [
        [$farahPayout, 1000_00],
        [$mogadishuLedger, -1000_00],
    ], null, $admin);

    expect($europeLedger->fresh()->balance_cents)->toBe(100000)
        ->and($mogadishuLedger->fresh()->balance_cents)->toBe(400000); // 5000 - 1000

    // 4. Ali sends $100 USD. Fee 5% = $5.00. Total debit $105.00.
    //    Sending share (50% of fee) = $2.50.
    $amount = 100_00;
    $fee = 5_00;
    $totalDebit = $amount + $fee;
    $sendingShare = (int) floor($fee * 5000 / 10000); // 250

    $service->post(JournalType::TransferSent, [
        [$aliSend, $totalDebit],
        [$transfersPayable, -$amount],
        [$feeIncome, -$fee],
        [$commissionExpense, $sendingShare],
        [$aliCommission, -$sendingShare],
    ], null, $ali);

    expect($aliSend->fresh()->balance_cents)->toBe(89500)
        ->and($aliCommission->fresh()->balance_cents)->toBe(250)
        ->and($feeIncome->fresh()->balance_cents)->toBe(500);

    // 5. Farah claims and pays. Payout share (20% of the remaining $2.50) = $0.50.
    $remaining = $fee - $sendingShare;
    $payoutShare = (int) floor($remaining * 2000 / 10000); // 50
    $companyShare = $fee - $sendingShare - $payoutShare;

    $service->post(JournalType::TransferPaid, [
        [$transfersPayable, $amount],
        [$farahPayout, -$amount],
        [$commissionExpense, $payoutShare],
        [$farahCommission, -$payoutShare],
    ], null, $farah);

    expect($aliSend->fresh()->balance_cents)->toBe(89500)
        ->and($aliCommission->fresh()->balance_cents)->toBe(250)
        ->and($farahPayout->fresh()->balance_cents)->toBe(90000)
        ->and($farahCommission->fresh()->balance_cents)->toBe(50)
        ->and($transfersPayable->fresh()->balance_cents)->toBe(0)
        ->and($feeIncome->fresh()->balance_cents)->toBe(500)
        ->and($commissionExpense->fresh()->balance_cents)->toBe(300)
        ->and($europeLedger->fresh()->balance_cents)->toBe(100000)
        ->and($mogadishuLedger->fresh()->balance_cents)->toBe(400000)
        ->and($companyShare)->toBe(200);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);

    // --- Continue the narrative: credit top-up, commission move, credit repayment.

    // Credit top-up $500 → send 1,395.00 / credit 500.00.
    $service->post(JournalType::TopupCredit, [
        [$aliCredit, 500_00],
        [$aliSend, -500_00],
    ], null, $admin);

    expect($aliSend->fresh()->balance_cents)->toBe(139500)
        ->and($aliCredit->fresh()->balance_cents)->toBe(50000);

    // Commission move → send 1,397.50 / commission 0.
    $service->post(JournalType::CommissionMove, [
        [$aliCommission, 250],
        [$aliSend, -250],
    ], null, $ali);

    expect($aliSend->fresh()->balance_cents)->toBe(139750)
        ->and($aliCommission->fresh()->balance_cents)->toBe(0);

    // Credit repayment $300 → credit 200.00, send unchanged.
    $service->post(JournalType::CreditRepayment, [
        [$europeLedger, 300_00],
        [$aliCredit, -300_00],
    ], null, $admin);

    expect($aliCredit->fresh()->balance_cents)->toBe(20000)
        ->and($aliSend->fresh()->balance_cents)->toBe(139750);
});

it('cancels a queued transfer with a full refund when fees.refund_on_cancel is true', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);
    $commission = $service->accountFor($agent, LedgerAccountType::AgentCommission);
    $payable = $service->system(LedgerAccountType::TransfersPayable);
    $feeIncome = $service->system(LedgerAccountType::FeeIncome);
    $commissionExpense = $service->system(LedgerAccountType::CommissionExpense);

    $service->post(JournalType::TopupPaid, [[$companyLedger, 200_00], [$send, -200_00]], null, $admin);

    $amount = 50_00;
    $fee = 2_50;
    $totalDebit = $amount + $fee;
    $sendingShare = 1_25;

    $service->post(JournalType::TransferSent, [
        [$send, $totalDebit],
        [$payable, -$amount],
        [$feeIncome, -$fee],
        [$commissionExpense, $sendingShare],
        [$commission, -$sendingShare],
    ], null, $agent);

    $sendAfterSend = $send->fresh()->balance_cents;

    // Full reversal: exact negation of every line.
    $service->post(JournalType::TransferCancelled, [
        [$send, -$totalDebit],
        [$payable, $amount],
        [$feeIncome, $fee],
        [$commissionExpense, -$sendingShare],
        [$commission, $sendingShare],
    ], null, $admin);

    expect($send->fresh()->balance_cents)->toBe($sendAfterSend + $totalDebit)
        ->and($send->fresh()->balance_cents)->toBe(200_00) // back to the pre-send balance
        ->and($payable->fresh()->balance_cents)->toBe(0)
        ->and($feeIncome->fresh()->balance_cents)->toBe(0)
        ->and($commission->fresh()->balance_cents)->toBe(0)
        ->and($commissionExpense->fresh()->balance_cents)->toBe(0);
});

it('cancels a queued transfer keeping the fee when fees.refund_on_cancel is false, but always reverses commission', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);
    $commission = $service->accountFor($agent, LedgerAccountType::AgentCommission);
    $payable = $service->system(LedgerAccountType::TransfersPayable);
    $feeIncome = $service->system(LedgerAccountType::FeeIncome);
    $commissionExpense = $service->system(LedgerAccountType::CommissionExpense);

    $service->post(JournalType::TopupPaid, [[$companyLedger, 200_00], [$send, -200_00]], null, $admin);

    $amount = 50_00;
    $fee = 2_50;
    $totalDebit = $amount + $fee;
    $sendingShare = 1_25;

    $service->post(JournalType::TransferSent, [
        [$send, $totalDebit],
        [$payable, -$amount],
        [$feeIncome, -$fee],
        [$commissionExpense, $sendingShare],
        [$commission, -$sendingShare],
    ], null, $agent);

    // Partial reversal: agent_send gets back only `amount`, fee_income/fx_income
    // lines are omitted entirely, sending commission is always reversed.
    $service->post(JournalType::TransferCancelled, [
        [$send, -$amount],
        [$payable, $amount],
        [$commissionExpense, -$sendingShare],
        [$commission, $sendingShare],
    ], null, $admin);

    expect($send->fresh()->balance_cents)->toBe(200_00 - $fee) // agent effectively loses the fee
        ->and($payable->fresh()->balance_cents)->toBe(0)
        ->and($feeIncome->fresh()->balance_cents)->toBe($fee) // company kept the fee
        ->and($commission->fresh()->balance_cents)->toBe(0) // commission always clawed back
        ->and($commissionExpense->fresh()->balance_cents)->toBe(0);
});

it('blocks an over-limit send and posts nothing', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);
    $payable = $service->system(LedgerAccountType::TransfersPayable);

    $service->post(JournalType::TopupPaid, [[$companyLedger, 50_00], [$send, -50_00]], null, $admin);

    expect(fn () => $service->post(JournalType::TransferSent, [
        [$send, 100_00],
        [$payable, -100_00],
    ], null, $agent))->toThrow(InsufficientBalanceException::class);

    expect($send->fresh()->balance_cents)->toBe(50_00)
        ->and(JournalLine::query()->where('ledger_account_id', $payable->id)->exists())->toBeFalse();
});
