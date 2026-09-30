<?php

use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

it('shows the send balance in the topbar on every page, not just the dashboard', function () {
    $agent = H::sendingAgent(fundCents: 123456); // $1,234.56

    foreach (['dashboard', 'transfers.index', 'transfers.create', 'commission.index'] as $route) {
        $this->actingAs($agent)->get(route($route))
            ->assertOk()
            ->assertSee('Send balance')
            ->assertSee('$1,234.56');
    }
});

it('shows it to an admin who also sends, not only to sending agents', function () {
    $admin = makeAdmin();

    // An admin with a send balance is still someone who sends.
    H::fund($admin, LedgerAccountType::AgentSend, 50000);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Send balance')
        ->assertSee('$500.00');
});

it('hides it from back-office staff who never send', function () {
    $admin = makeAdmin();

    expect($admin->sendBalanceCents())->toBeNull();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Send balance');
});

it('shows a sending agent zero before they are ever topped up', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    expect($agent->sendBalanceCents())->toBe(0);

    $this->actingAs($agent)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Send balance')
        ->assertSee('$0.00');
});

it('never creates a ledger account just by rendering the chip', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    $before = LedgerAccount::query()->count();

    $this->actingAs($agent)->get(route('dashboard'))->assertOk();

    expect(LedgerAccount::query()->count())->toBe($before);
});

it('looks the balance up once per request', function () {
    $agent = H::sendingAgent(fundCents: 50000);

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'ledger_accounts')) {
            $queries++;
        }
    });

    $agent->sendBalanceCents();
    $agent->sendBalanceCents();
    $agent->sendBalanceCents();

    expect($queries)->toBe(1);
});
