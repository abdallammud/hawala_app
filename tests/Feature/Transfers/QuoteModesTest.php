<?php

use App\Enums\LedgerAccountType;
use App\Enums\QuoteMode;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
    Settings::set('currency.margin_bp', 100); // EUR at 1.08 → customer rate 1.0692
});

/**
 * The form posts both legs and says which one the agent typed; the other was
 * filled in by the rate and the server ignores it.
 */
function postEuroTransfer(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'branch_id' => Branch::query()->first()->id,
        'sender_name' => 'Ali Hassan',
        'sender_phone' => '+46701234567',
        'receiver_name' => 'Farah Omar',
        'receiver_phone' => '+252611234567',
        'mode' => 'receive',
        'payout_currency' => 'USD',
        'payout_amount' => '100',
        'currency' => 'EUR',
        'pay_amount' => '98.21',
        'confirm_duplicate' => '1',
    ], $overrides);
}

it('shows both legs on the form, each with its own currency', function () {
    $this->actingAs(H::sendingAgent())
        ->get('/transfers/create')
        ->assertOk()
        ->assertSee('Receiver gets')
        ->assertSee('Customer pays')
        ->assertSee('name="payout_amount"', false)
        ->assertSee('name="payout_currency"', false)
        ->assertSee('name="pay_amount"', false)
        ->assertSee('name="currency"', false);
});

it('opens the pay box on the currency that agent used last', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    // First visit: nothing to go on, so USD.
    $this->actingAs($agent)->get('/transfers/create')->assertOk()
        ->assertSee("payCurrency: 'USD'", false);

    $this->actingAs($agent)->post('/transfers', postEuroTransfer());

    // Next customer: the euro box is already selected.
    $this->actingAs($agent)->get('/transfers/create')->assertOk()
        ->assertSee("payCurrency: 'EUR'", false);
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
        ->and($transfer->fx_income_cents)->toBe(107)
        ->and($transfer->total_debit_cents)->toBe(10607);

    expect(JournalLine::query()->sum('amount_cents'))->toBe(0)
        ->and(app(LedgerService::class)->accountFor($agent, LedgerAccountType::AgentSend)->fresh()->balance_cents)
        ->toBe(100000 - 10607);
});

it('takes the fee out of the euros the customer hands over', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['mode' => 'pay', 'pay_amount' => '100']))
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

it('ignores the auto-filled leg — only the typed one counts', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    // A stale (or tampered) euro figure must not change what is charged.
    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['pay_amount' => '1.00']))
        ->assertRedirect();

    expect(Transfer::query()->firstOrFail()->collect_original_cents)->toBe(9821);
});

it('accepts a payout typed in euros and still pays out in USD', function () {
    Settings::set('currency.margin_bp', 0);
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['payout_currency' => 'EUR', 'payout_amount' => '100']))
        ->assertRedirect();

    $transfer = Transfer::query()->firstOrFail();

    expect($transfer->amount_cents)->toBe(10800)   // €100 at the market rate
        ->and($transfer->fee_cents)->toBe(540)
        ->and($transfer->collect_original_cents)->toBe(10500)
        ->and($transfer->original_currency)->toBe('EUR');
});

it('needs the leg it says the agent typed', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['mode' => 'pay', 'pay_amount' => null]))
        ->assertSessionHasErrors('pay_amount');

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['payout_amount' => null]))
        ->assertSessionHasErrors('payout_amount');

    expect(Transfer::query()->count())->toBe(0);
});

it('rejects an amount too small to cover the fee', function () {
    Settings::set('fees.min_fee_cents', 500);

    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['mode' => 'pay', 'pay_amount' => '1']))
        ->assertSessionHasErrors('pay_amount');

    expect(Transfer::query()->count())->toBe(0);
});

it('warns about a duplicate on the leg the agent typed', function () {
    $agent = H::sendingAgent(fundCents: 100000);

    $this->actingAs($agent)->post('/transfers', postEuroTransfer());

    $this->actingAs($agent)
        ->post('/transfers', postEuroTransfer(['confirm_duplicate' => '0']))
        ->assertSessionHas('duplicate_warning');

    expect(Transfer::query()->count())->toBe(1);
});
