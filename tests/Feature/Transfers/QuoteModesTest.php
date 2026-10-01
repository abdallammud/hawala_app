<?php

use App\Enums\LedgerAccountType;
use App\Enums\QuoteMode;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
    Settings::set('currency.margin_bp', 100); // EUR at 1.08 → customer rate 1.0692
});

function postEuroTransfer(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => Branch::query()->first()->id,
        'sender_name' => 'Ali Hassan',
        'sender_phone' => '+46701234567',
        'receiver_name' => 'Farah Omar',
        'receiver_phone' => '+252611234567',
        'currency' => 'EUR',
        'mode' => 'receive',
        'amount' => '100',
        'confirm_duplicate' => '1',
    ], $overrides);
}

it('offers both directions on the form', function () {
    $this->actingAs(H::sendingAgent())
        ->get('/transfers/create')
        ->assertOk()
        ->assertSee('Customer pays')
        ->assertSee('Receiver gets')
        ->assertSee('Currency the customer pays in');
});

it('answers "how much do I pay so he gets $100 in Banadir?"', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)->post('/transfers', postEuroTransfer())->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();

    // $100 + $5 fee = $105, bought at 1.0692 → €98.21 (rounded up).
    expect($transfer->quote_mode)->toBe(QuoteMode::Receive)
        ->and($transfer->amount_cents)->toBe(10000)
        ->and($transfer->fee_cents)->toBe(500)
        ->and($transfer->collect_original_cents)->toBe(9821)
        ->and($transfer->round_up_original_cents)->toBe(0)
        ->and($transfer->fx_income_cents)->toBe(107)
        ->and($transfer->total_debit_cents)->toBe(10607);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0)
        ->and(app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend)->fresh()->balance_cents)
        ->toBe(100000 - 10607);
});

it('takes the fee out of the euros the customer hands over', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['mode' => 'pay', 'amount' => '100']))
        ->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();

    expect($transfer->quote_mode)->toBe(QuoteMode::Pay)
        ->and($transfer->collect_original_cents)->toBe(10000)
        ->and($transfer->amount_cents)->toBe(10183)
        ->and($transfer->fee_cents)->toBe(509)
        ->and($transfer->fx_income_cents)->toBe(108)
        // The €100 was worth $108 at the market rate, and all of it is posted.
        ->and($transfer->total_debit_cents)->toBe(10800);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0);
});

it('lets a permitted agent round the collected amount up, and keeps the extra', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo(Permission::findByName('transfers.round_collect'));

    // €98.21 computed, agent asks for a round €99.
    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['collect' => '99.00']))
        ->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();

    expect($transfer->collect_original_cents)->toBe(9900)
        ->and($transfer->round_up_original_cents)->toBe(79)
        // The receiver is unaffected — only the company's side moves.
        ->and($transfer->amount_cents)->toBe(10000)
        ->and($transfer->fee_cents)->toBe(500)
        ->and($transfer->fx_income_cents)->toBe(192)   // 107 + €0.79 at 1.08
        ->and($transfer->total_debit_cents)->toBe(10692);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0)
        ->and(app(LedgerService::class)->system(LedgerAccountType::FxIncome)->fresh()->balance_cents)->toBe(192);
});

it('refuses a round-up from an agent without the permission', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    expect($agent->can('transfers.round_collect'))->toBeFalse();

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['collect' => '99.00']))
        ->assertSessionHasErrors('collect');

    expect(Transfer::query()->count())->toBe(0);
});

it('refuses collecting less than the maths says', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo(Permission::findByName('transfers.round_collect'));

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['collect' => '90.00']))
        ->assertSessionHasErrors('collect');

    expect(Transfer::query()->count())->toBe(0);
});

it('refuses a round-up beyond the ceiling, and clamps one that slips past', function () {
    Settings::set('currency.max_round_up_bp', 500); // 5% of €98.21 = €4.91

    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo(Permission::findByName('transfers.round_collect'));

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['collect' => '150.00']))
        ->assertSessionHasErrors('collect');

    expect(Transfer::query()->count())->toBe(0);

    // The service clamps too, so a caller that skips the form cannot overcharge.
    $transfer = app(TransferService::class)->create(
        $agent,
        $this->branch,
        H::transferPayload([
            'currency' => 'EUR',
            'amount_cents' => 10000,
            'mode' => QuoteMode::Receive,
            'round_up_cents' => 5000,
        ]),
        (string) Str::uuid(),
    );

    expect($transfer->round_up_original_cents)->toBe(491)
        ->and($transfer->collect_original_cents)->toBe(9821 + 491);
});

it('ignores a round-up on a USD transfer, where there is no spread', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $agent->givePermissionTo(Permission::findByName('transfers.round_collect'));

    $transfer = app(TransferService::class)->create(
        $agent,
        $this->branch,
        H::transferPayload(['amount_cents' => 10000, 'mode' => QuoteMode::Receive, 'round_up_cents' => 500]),
        (string) Str::uuid(),
    );

    expect($transfer->round_up_original_cents)->toBe(0)
        ->and($transfer->collect_original_cents)->toBe(10500)
        ->and($transfer->fx_income_cents)->toBe(0);
});

it('rejects an amount too small to cover the fee', function () {
    Settings::set('fees.min_fee_cents', 500);

    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['mode' => 'pay', 'amount' => '1']))
        ->assertSessionHasErrors('amount');

    expect(Transfer::query()->count())->toBe(0);
});
