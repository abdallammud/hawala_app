<?php

use App\Models\Customer;
use App\Models\Transfer;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Transfers\TransferService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

function custSend(User $agent, array $overrides = []): Transfer
{
    return app(TransferService::class)->create(
        $agent, test()->branch, H::transferPayload($overrides), (string) Str::uuid()
    );
}

function custFilters(?int $customerId = null): ReportFilters
{
    return new ReportFilters(CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addDay(), customerId: $customerId);
}

// --- Registering customers --------------------------------------------------

it('registers the sender as a customer and links the transfer', function () {
    $agent = H::sendingAgent();

    $transfer = custSend($agent, ['sender_name' => 'Ali Hassan', 'sender_phone' => '+46 70 123 4567']);

    $customer = Customer::sole();

    expect($customer->phone)->toBe('46701234567')
        ->and($customer->name)->toBe('Ali Hassan')
        ->and($customer->phoneLabel())->toBe('+46 70 123 4567')
        ->and($customer->created_by)->toBe($agent->id)
        ->and($transfer->customer_id)->toBe($customer->id);
});

it('recognises the same person however the number is written', function () {
    $agent = H::sendingAgent();

    $a = custSend($agent, ['sender_phone' => '+46701234567']);
    $b = custSend($agent, ['sender_phone' => '0046 70 123 45 67']);
    $c = custSend($agent, ['sender_phone' => '+46701239999']);

    expect(Customer::count())->toBe(2)
        ->and($b->customer_id)->toBe($a->customer_id)
        ->and($c->customer_id)->not->toBe($a->customer_id);
});

it('fills blank customer details from a later transfer but never overwrites', function () {
    $agent = H::sendingAgent();

    custSend($agent, ['sender_country' => 'Sweden', 'sender_city' => 'Stockholm', 'sender_province' => null]);
    custSend($agent, ['sender_country' => 'Norway', 'sender_city' => 'Oslo', 'sender_province' => 'Viken']);

    $customer = Customer::sole();

    expect($customer->country)->toBe('Sweden')
        ->and($customer->city)->toBe('Stockholm')
        ->and($customer->province)->toBe('Viken');
});

// --- Locations on the transfer ----------------------------------------------

it('keeps country, city and province for both sender and receiver on the transfer', function () {
    $transfer = custSend(H::sendingAgent());

    expect($transfer->sender_country)->toBe('Sweden')
        ->and($transfer->sender_city)->toBe('Stockholm')
        ->and($transfer->sender_province)->toBeNull()
        ->and($transfer->receiver_country)->toBe('Somalia')
        ->and($transfer->receiver_city)->toBe('Mogadishu')
        ->and($transfer->receiver_province)->toBe('Banadir');
});

it('needs country and city for both sides, province is optional', function () {
    $agent = H::sendingAgent(fundCents: 100000);
    $base = [
        'idempotency_key' => (string) Str::uuid(), 'branch_id' => $this->branch->id,
        'sender_name' => 'A', 'sender_phone' => '+4670111', 'receiver_name' => 'B', 'receiver_phone' => '+25261111',
        'currency' => 'USD', 'payout_currency' => 'USD', 'mode' => 'receive', 'payout_amount' => '50',
        'confirm_duplicate' => '1',
    ];

    $this->actingAs($agent)->post('/transfers', $base)
        ->assertSessionHasErrors(['sender_country', 'sender_city', 'receiver_country', 'receiver_city']);

    $this->actingAs($agent)->post('/transfers', $base + [
        'sender_country' => 'Sweden', 'sender_city' => 'Malmö',
        'receiver_country' => 'Somalia', 'receiver_city' => 'Hargeisa',
    ])->assertSessionDoesntHaveErrors();

    expect(Transfer::sole()->receiver_city)->toBe('Hargeisa');
});

it('shows separate Sender and Receiver fieldsets with a customer search', function () {
    $this->actingAs(H::sendingAgent())->get('/transfers/create')->assertOk()
        ->assertSee('Find an existing customer')
        ->assertSee('name="sender_country"', false)->assertSee('name="sender_province"', false)
        ->assertSee('name="receiver_country"', false)->assertSee('name="receiver_province"', false)
        ->assertSee("value: 'Somalia'", false);
});

it('prefills the form from a customer', function () {
    $agent = H::sendingAgent();
    $customer = Customer::find(custSend($agent, ['sender_name' => 'Hodan Nur'])->customer_id);

    $this->actingAs($agent)->get('/transfers/create?customer='.$customer->id)->assertOk()
        ->assertSee('value="Hodan Nur"', false)
        ->assertSee('value="Stockholm"', false);
});

// --- Search ------------------------------------------------------------------

it('finds customers by name or by part of the phone number', function () {
    $agent = H::sendingAgent();
    custSend($agent, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+46 70 111 2222']);
    custSend($agent, ['sender_name' => 'Abdi Warsame', 'sender_phone' => '+44 7700 900123']);

    $this->actingAs($agent)->getJson('/customers/search?q=hod')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Hodan Nur')
        ->assertJsonPath('0.phone_display', '+46 70 111 2222')->assertJsonPath('0.city', 'Stockholm');

    $this->actingAs($agent)->getJson('/customers/search?q=7700 900')
        ->assertOk()->assertJsonPath('0.name', 'Abdi Warsame');

    $this->actingAs($agent)->getJson('/customers/search?q=h')->assertOk()->assertJsonCount(0);
});

it('only searches the agent’s own customers, but an admin searches everyone’s', function () {
    $a = H::sendingAgent();
    $b = H::sendingAgent();
    custSend($a, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111']);

    $this->actingAs($b)->getJson('/customers/search?q=hodan')->assertOk()->assertJsonCount(0);
    $this->actingAs(makeAdmin())->getJson('/customers/search?q=hodan')->assertOk()->assertJsonCount(1);
});

it('keeps the search away from people who cannot create transfers', function () {
    $this->actingAs(H::payoutAgent($this->branch))->getJson('/customers/search?q=hodan')->assertForbidden();
});

// --- Pages -------------------------------------------------------------------

it('lists and shows an agent’s customers with their totals', function () {
    $agent = H::sendingAgent();
    custSend($agent, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111', 'amount_cents' => 10000]);
    $second = custSend($agent, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111', 'amount_cents' => 20000]);

    $this->actingAs($agent)->get('/customers')->assertOk()->assertSee('Hodan Nur')->assertSee('$300.00');

    $this->actingAs($agent)->get('/customers/'.$second->customer_id)->assertOk()
        ->assertSee('Hodan Nur')->assertSee($second->code)->assertSee('New transfer')
        ->assertSee('Statement report');
});

it('hides other agents’ customers', function () {
    $a = H::sendingAgent();
    $b = H::sendingAgent();
    $transfer = custSend($a, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111']);

    $this->actingAs($b)->get('/customers')->assertOk()->assertDontSee('Hodan Nur');
    $this->actingAs($b)->get('/customers/'.$transfer->customer_id)->assertForbidden();
    $this->actingAs(makeAdmin())->get('/customers/'.$transfer->customer_id)->assertOk();
});

it('lets only users with customers.edit change a customer', function () {
    $agent = H::sendingAgent();
    $customer = Customer::find(custSend($agent, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111'])->customer_id);
    $other = Customer::find(custSend($agent, ['sender_name' => 'Abdi', 'sender_phone' => '+4670222'])->customer_id);

    $this->actingAs($agent)->get("/customers/{$customer->id}/edit")->assertForbidden();

    $admin = makeAdmin();
    $this->actingAs($admin)->put("/customers/{$customer->id}", [
        'name' => 'Hodan Nur Ali', 'phone' => '+46 70 111', 'country' => 'Norway', 'city' => 'Oslo', 'province' => '',
    ])->assertRedirect();

    expect($customer->fresh()->name)->toBe('Hodan Nur Ali')->and($customer->fresh()->city)->toBe('Oslo');

    $this->actingAs($admin)->put("/customers/{$customer->id}", [
        'name' => 'Hodan', 'phone' => '+4670222',
    ])->assertSessionHasErrors('phone_digits');

    expect($other->fresh()->phone)->toBe('4670222');
});

it('puts Customers in the menu for those who may see them', function () {
    $this->actingAs(H::sendingAgent())->get('/dashboard')->assertSee('href="'.route('customers.index').'"', false);
    $this->actingAs(H::payoutAgent($this->branch))->get('/dashboard')->assertDontSee('href="'.route('customers.index').'"', false);
});

// --- Customer reports ----------------------------------------------------------

it('summarises customers per period, scoped to what the user may see', function () {
    $a = H::sendingAgent();
    $b = H::sendingAgent();
    custSend($a, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111', 'amount_cents' => 10000]);
    custSend($a, ['sender_name' => 'Hodan Nur', 'sender_phone' => '+4670111', 'amount_cents' => 20000]);
    custSend($b, ['sender_name' => 'Abdi Warsame', 'sender_phone' => '+4670222', 'amount_cents' => 5000]);

    $report = app(ReportRegistry::class)->find('customers');

    $forA = $report->rows(custFilters(), $a);
    $forAdmin = $report->rows(custFilters(), makeAdmin());

    expect($forA)->toHaveCount(1)
        ->and($forA[0]['customer'])->toBe('Hodan Nur')
        ->and($forA[0]['transfers'])->toBe(2)
        ->and($forA[0]['amount'])->toBe(30000)
        ->and($forAdmin)->toHaveCount(2)
        ->and($report->totals($forAdmin)['amount'])->toBe(35000);
});

it('gives a per-customer statement and nothing for a customer you cannot see', function () {
    $a = H::sendingAgent();
    $b = H::sendingAgent();
    $mine = custSend($a, ['sender_phone' => '+4670111', 'amount_cents' => 10000]);
    custSend($b, ['sender_phone' => '+4670222', 'amount_cents' => 5000]);

    $report = app(ReportRegistry::class)->find('customer-statement');

    expect($report->rows(custFilters(), $a))->toHaveCount(0) // no customer picked
        ->and($report->rows(custFilters($mine->customer_id), $a))->toHaveCount(1)
        ->and($report->rows(custFilters($mine->customer_id), $a)[0]['code'])->toBe($mine->code)
        ->and($report->rows(custFilters(Customer::where('phone', '4670222')->value('id')), $a))->toHaveCount(0);

    $this->actingAs($a)->get('/reports/customer-statement?customer_id='.$mine->customer_id.'&from=2000-01-01')
        ->assertOk()->assertSee($mine->code);
    $this->actingAs($a)->get('/reports/customer-statement')->assertOk()->assertSee('Pick a customer');
});
