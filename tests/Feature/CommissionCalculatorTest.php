<?php

use App\Enums\CommissionType;
use App\Enums\UserType;
use App\Models\User;
use App\Services\CommissionCalculator;
use App\Support\Settings;

it('matches the BUILD.md §6 example exactly', function () {
    $sender = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_type' => CommissionType::Percent, 'send_commission_value' => 5000]);
    $payer = User::factory()->create(['type' => UserType::PayoutAgent, 'payout_commission_type' => CommissionType::Percent, 'payout_commission_value' => 2000]);

    $calculator = app(CommissionCalculator::class);
    $fee = 500; // $5.00

    $sending = $calculator->sendingShare($sender, $fee);
    expect($sending['share_cents'])->toBe(250);

    $payout = $calculator->payoutShare($payer, $fee, $sending['share_cents']);
    expect($payout['share_cents'])->toBe(50);

    $company = $calculator->companyShare($fee, $sending['share_cents'], $payout['share_cents']);
    expect($company)->toBe(200);
});

it('resolves to the settings default when the user has no override', function () {
    Settings::set('commission.send_type', 'percent');
    Settings::set('commission.send_value', 3000); // 30%

    $sender = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_type' => null, 'send_commission_value' => null]);

    $share = app(CommissionCalculator::class)->sendingShare($sender, 1000);

    expect($share['share_cents'])->toBe(300)
        ->and($share['snapshot']['type'])->toBe('percent')
        ->and($share['snapshot']['value'])->toBe(3000);
});

it('rounds percent shares down, never up', function () {
    $sender = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_type' => CommissionType::Percent, 'send_commission_value' => 3333]); // 33.33%

    // 100 * 33.33% = 33.33 -> floors to 33
    $share = app(CommissionCalculator::class)->sendingShare($sender, 100);

    expect($share['share_cents'])->toBe(33);
});

it('caps a flat share at the fee and flags it in the snapshot', function () {
    $sender = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_type' => CommissionType::Flat, 'send_commission_value' => 1000]);

    $share = app(CommissionCalculator::class)->sendingShare($sender, 300); // fee smaller than the flat amount

    expect($share['share_cents'])->toBe(300)
        ->and($share['snapshot']['capped'])->toBeTrue();
});

it('gives none type a zero share', function () {
    $sender = User::factory()->create(['type' => UserType::SendingAgent, 'send_commission_type' => CommissionType::None, 'send_commission_value' => null]);

    expect(app(CommissionCalculator::class)->sendingShare($sender, 1000)['share_cents'])->toBe(0);
});

it('computes the payout share as a percent of the remaining fee, not the whole fee', function () {
    $payer = User::factory()->create(['type' => UserType::PayoutAgent, 'payout_commission_type' => CommissionType::Percent, 'payout_commission_value' => 5000]); // 50%

    // fee 1000, sending share already took 600, remaining 400 -> 50% of 400 = 200
    $share = app(CommissionCalculator::class)->payoutShare($payer, 1000, 600);

    expect($share['share_cents'])->toBe(200);
});

it('never lets the company share go negative', function () {
    $company = app(CommissionCalculator::class)->companyShare(feeCents: 500, sendingShareCents: 300, payoutShareCents: 300);

    expect($company)->toBe(0);
});
