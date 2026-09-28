<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Ledger\UnbalancedEntryException;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;

function ledgerService(): LedgerService
{
    return app(LedgerService::class);
}

function makeCompanyAccount(string $name = 'Europe office'): CompanyAccount
{
    return CompanyAccount::create(['name' => $name, 'location' => 'europe', 'is_active' => true]);
}

it('rejects an entry with fewer than two lines', function () {
    $service = ledgerService();
    $company = makeCompanyAccount();
    $account = $service->accountFor($company, LedgerAccountType::Company);
    $admin = User::factory()->create();

    $service->post(JournalType::ManualAdjustment, [[$account, 100]], null, $admin);
})->throws(UnbalancedEntryException::class);

it('rejects a zero-amount line', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $company = makeCompanyAccount();
    $a = $service->accountFor($company, LedgerAccountType::Company);
    $b = $service->system(LedgerAccountType::Adjustments);

    $service->post(JournalType::ManualAdjustment, [[$a, 0], [$b, 0]], null, $admin);
})->throws(UnbalancedEntryException::class);

it('rejects lines that do not sum to zero', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $company = makeCompanyAccount();
    $a = $service->accountFor($company, LedgerAccountType::Company);
    $b = $service->system(LedgerAccountType::Adjustments);

    $service->post(JournalType::ManualAdjustment, [[$a, 1000], [$b, -900]], null, $admin);
})->throws(UnbalancedEntryException::class);

it('posts a balanced manual adjustment and updates natural-sign balances', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $company = makeCompanyAccount();
    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $adjustments = $service->system(LedgerAccountType::Adjustments);

    $entry = $service->post(
        JournalType::ManualAdjustment,
        [[$companyLedger, 500_00], [$adjustments, -500_00]],
        null,
        $admin,
        $admin,
        'Opening balance'
    );

    expect($entry->uuid)->not->toBeNull()
        ->and($entry->lines)->toHaveCount(2)
        ->and($companyLedger->fresh()->balance_cents)->toBe(50000)
        ->and($adjustments->fresh()->balance_cents)->toBe(-50000); // debit-normal, credited: 0 + (-50000)

    expect(JournalLine::query()->where('journal_entry_id', $entry->id)->sum('amount_cents'))->toBe(0);
});

it('reuses the same ledger account for the same owner and type', function () {
    $service = ledgerService();
    $company = makeCompanyAccount();

    $first = $service->accountFor($company, LedgerAccountType::Company);
    $second = $service->accountFor($company, LedgerAccountType::Company);

    expect($first->id)->toBe($second->id);
});

it('reuses the same system account across calls', function () {
    $service = ledgerService();

    $first = $service->system(LedgerAccountType::FeeIncome);
    $second = $service->system(LedgerAccountType::FeeIncome);

    expect($first->id)->toBe($second->id);
});

it('blocks a guarded account from going negative and posts nothing', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);
    $company = makeCompanyAccount();
    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);

    // Agent has 0 send balance; try to debit 100 from it (a "send" would credit
    // agent_send, so to force a negative we credit-normal-decrease it directly).
    expect(fn () => $service->post(
        JournalType::TopupPaid,
        [[$companyLedger, -100_00], [$send, 100_00]],
        null,
        $admin
    ))->toThrow(InsufficientBalanceException::class);

    expect(JournalEntry::query()->count())->toBe(0)
        ->and($send->fresh()->balance_cents)->toBe(0)
        ->and($companyLedger->fresh()->balance_cents)->toBe(0);
});

it('blocks payout balance from going negative by default', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $payout = $service->accountFor($agent, LedgerAccountType::AgentPayout);
    $payable = $service->system(LedgerAccountType::TransfersPayable);

    expect(fn () => $service->post(
        JournalType::TransferPaid,
        [[$payable, 100_00], [$payout, -100_00]],
        null,
        $admin
    ))->toThrow(InsufficientBalanceException::class);
});

it('allows payout balance to go negative when the setting permits it', function () {
    Settings::set('payout.allow_negative', true);

    $service = ledgerService();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $payout = $service->accountFor($agent, LedgerAccountType::AgentPayout);
    $payable = $service->system(LedgerAccountType::TransfersPayable);

    $service->post(
        JournalType::TransferPaid,
        [[$payable, 100_00], [$payout, -100_00]],
        null,
        $admin
    );

    expect($payout->fresh()->balance_cents)->toBe(-10000);
});

it('only the first of two sequential over-limit sends succeeds', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $company = makeCompanyAccount();
    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);

    // Fund the agent with exactly $100.
    $service->post(JournalType::TopupPaid, [[$companyLedger, 100_00], [$send, -100_00]], null, $admin);

    $payable = $service->system(LedgerAccountType::TransfersPayable);

    // Two "sends" of $60 each would together exceed the $100 balance.
    $service->post(JournalType::TransferSent, [[$send, 60_00], [$payable, -60_00]], null, $admin);

    expect(fn () => $service->post(JournalType::TransferSent, [[$send, 60_00], [$payable, -60_00]], null, $admin))
        ->toThrow(InsufficientBalanceException::class);

    expect($send->fresh()->balance_cents)->toBe(4000);
});

it('every journal type in BUILD.md §4 posts a balanced entry', function () {
    $service = ledgerService();
    $admin = User::factory()->create();
    $sender = User::factory()->create();
    $payer = User::factory()->create();
    $company = makeCompanyAccount();
    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);

    $send = $service->accountFor($sender, LedgerAccountType::AgentSend);
    $credit = $service->accountFor($sender, LedgerAccountType::AgentCredit);
    $senderCommission = $service->accountFor($sender, LedgerAccountType::AgentCommission);
    $payout = $service->accountFor($payer, LedgerAccountType::AgentPayout);
    $payerCommission = $service->accountFor($payer, LedgerAccountType::AgentCommission);
    $payable = $service->system(LedgerAccountType::TransfersPayable);
    $feeIncome = $service->system(LedgerAccountType::FeeIncome);
    $commissionExpense = $service->system(LedgerAccountType::CommissionExpense);
    $bankCharges = $service->system(LedgerAccountType::BankCharges);
    $adjustments = $service->system(LedgerAccountType::Adjustments);
    $otherCompany = makeCompanyAccount('Mogadishu bank');
    $otherCompanyLedger = $service->accountFor($otherCompany, LedgerAccountType::Company);

    // topup_paid
    $service->post(JournalType::TopupPaid, [[$companyLedger, 1000_00], [$send, -1000_00]], null, $admin);
    expect($send->fresh()->balance_cents)->toBe(100000);

    // topup_credit
    $service->post(JournalType::TopupCredit, [[$credit, 200_00], [$send, -200_00]], null, $admin);
    expect($credit->fresh()->balance_cents)->toBe(20000)
        ->and($send->fresh()->balance_cents)->toBe(120000);

    // credit_repayment
    $service->post(JournalType::CreditRepayment, [[$companyLedger, 50_00], [$credit, -50_00]], null, $admin);
    expect($credit->fresh()->balance_cents)->toBe(15000);

    // transfer_sent (amount 100, fee 5, sending share 2.50)
    $service->post(JournalType::TransferSent, [
        [$send, 105_00],
        [$payable, -100_00],
        [$feeIncome, -5_00],
        [$commissionExpense, 2_50],
        [$senderCommission, -2_50],
    ], null, $admin);
    expect($send->fresh()->balance_cents)->toBe(120000 - 10500)
        ->and($payable->fresh()->balance_cents)->toBe(10000)
        ->and($feeIncome->fresh()->balance_cents)->toBe(500)
        ->and($senderCommission->fresh()->balance_cents)->toBe(250);

    // commission_move
    $service->post(JournalType::CommissionMove, [[$senderCommission, 2_50], [$send, -2_50]], null, $admin);
    expect($senderCommission->fresh()->balance_cents)->toBe(0);

    // commission_withdraw
    $service->post(JournalType::ManualAdjustment, [[$commissionExpense, 10_00], [$senderCommission, -10_00]], null, $admin); // fund it first
    $service->post(JournalType::CommissionWithdraw, [[$senderCommission, 10_00], [$companyLedger, -10_00]], null, $admin);
    expect($senderCommission->fresh()->balance_cents)->toBe(0);

    // payout_funding
    $service->post(JournalType::PayoutFunding, [[$payout, 100_00], [$otherCompanyLedger, -100_00]], null, $admin);
    expect($payout->fresh()->balance_cents)->toBe(10000);

    // transfer_paid (amount 100, remaining fee 2.50, payout share 0.50)
    $service->post(JournalType::TransferPaid, [
        [$payable, 100_00],
        [$payout, -100_00],
        [$commissionExpense, 50],
        [$payerCommission, -50],
    ], null, $admin);
    expect($payable->fresh()->balance_cents)->toBe(0)
        ->and($payout->fresh()->balance_cents)->toBe(0)
        ->and($payerCommission->fresh()->balance_cents)->toBe(50);

    // payout_return
    $service->post(JournalType::PayoutFunding, [[$payout, 20_00], [$otherCompanyLedger, -20_00]], null, $admin);
    $service->post(JournalType::PayoutReturn, [[$otherCompanyLedger, 20_00], [$payout, -20_00]], null, $admin);
    expect($payout->fresh()->balance_cents)->toBe(0);

    // company_transfer (with a bank charge)
    $service->post(JournalType::CompanyTransfer, [
        [$otherCompanyLedger, 95_00],
        [$bankCharges, 5_00],
        [$companyLedger, -100_00],
    ], null, $admin);
    expect($bankCharges->fresh()->balance_cents)->toBe(500);

    // manual_adjustment
    $service->post(JournalType::ManualAdjustment, [[$adjustments, 10_00], [$companyLedger, -10_00]], null, $admin, $admin, 'test');
    expect($adjustments->fresh()->balance_cents)->toBe(1000);

    // transfer_cancelled — exact negation of a fresh transfer_sent, refund on
    $service->post(JournalType::TransferSent, [
        [$send, 105_00],
        [$payable, -100_00],
        [$feeIncome, -5_00],
        [$commissionExpense, 2_50],
        [$senderCommission, -2_50],
    ], null, $admin);
    $sendBeforeCancel = $send->fresh()->balance_cents;
    $service->post(JournalType::TransferCancelled, [
        [$send, -105_00],
        [$payable, 100_00],
        [$feeIncome, 5_00],
        [$commissionExpense, -2_50],
        [$senderCommission, 2_50],
    ], null, $admin);
    expect($send->fresh()->balance_cents)->toBe($sendBeforeCancel + 10500)
        ->and($senderCommission->fresh()->balance_cents)->toBe(0);

    // transfer_rejected — same shape as cancelled with full refund
    $service->post(JournalType::TransferSent, [
        [$send, 105_00],
        [$payable, -100_00],
        [$feeIncome, -5_00],
        [$commissionExpense, 2_50],
        [$senderCommission, -2_50],
    ], null, $admin);
    $sendBeforeReject = $send->fresh()->balance_cents;
    $service->post(JournalType::TransferRejected, [
        [$send, -105_00],
        [$payable, 100_00],
        [$feeIncome, 5_00],
        [$commissionExpense, -2_50],
        [$senderCommission, 2_50],
    ], null, $admin);
    expect($send->fresh()->balance_cents)->toBe($sendBeforeReject + 10500);

    // Every journal_line across this whole test must still net to zero.
    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
});
