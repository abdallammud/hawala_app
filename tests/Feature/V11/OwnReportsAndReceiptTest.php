<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Models\Transfer;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\ReceiptService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();

    // Two senders and two payers in the same branch, one paid transfer each.
    $this->senderA = H::sendingAgent();
    $this->senderB = H::sendingAgent();
    $this->payerA = H::payoutAgent($this->branch);
    $this->payerB = H::payoutAgent($this->branch);

    $queue = app(PayoutQueueService::class);

    $this->tA = app(TransferService::class)->create($this->senderA, $this->branch, H::transferPayload(['amount_cents' => 10000, 'sender_phone' => '+4670111']), (string) Str::uuid());
    $queue->claim($this->tA, $this->payerA);
    $this->tA = $queue->markPaid($this->tA->fresh(), $this->payerA, H::payoutAccountFor($this->payerA));

    $this->tB = app(TransferService::class)->create($this->senderB, $this->branch, H::transferPayload(['amount_cents' => 20000, 'sender_phone' => '+4670222']), (string) Str::uuid());
    $queue->claim($this->tB, $this->payerB);
    $this->tB = $queue->markPaid($this->tB->fresh(), $this->payerB, H::payoutAccountFor($this->payerB));
});

function ownFilters(): ReportFilters
{
    return new ReportFilters(CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addDay());
}

function ownRows(string $key, $user)
{
    return app(ReportRegistry::class)->find($key)->rows(ownFilters(), $user);
}

// --- Item 5: each sub-user's own reports ---------------------------------------

it('shows a sending agent only the transfers they sent', function () {
    expect(ownRows('transfers', $this->senderA)->pluck('code')->all())->toBe([$this->tA->code])
        ->and(ownRows('transfers', $this->senderB)->pluck('code')->all())->toBe([$this->tB->code])
        ->and(ownRows('transfers', makeAdmin()))->toHaveCount(2);
});

it('shows a payout agent only the transfers they paid, not the rest of the branch', function () {
    expect(ownRows('transfers', $this->payerA)->pluck('code')->all())->toBe([$this->tA->code])
        ->and(ownRows('transfers', $this->payerB)->pluck('code')->all())->toBe([$this->tB->code]);
});

it('scopes payouts by method to the agent who paid', function () {
    $rows = ownRows('payouts-by-method', $this->payerA);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['agent'])->toBe($this->payerA->name)
        ->and($rows[0]['total'])->toBe(10000);
});

it('scopes the commissions report to the signed-in agent', function () {
    $mine = ownRows('commissions', $this->senderA);

    expect($mine)->toHaveCount(1)->and($mine[0]['agent'])->toBe($this->senderA->name)
        ->and($mine[0]['earned_sending'])->toBe($this->tA->sending_share_cents);

    expect(ownRows('commissions', $this->payerA)->pluck('agent')->all())->toBe([$this->payerA->name]);
    expect(ownRows('commissions', makeAdmin()))->toHaveCount(4);
});

it('scopes the agent statement to the signed-in agent, whoever they ask for', function () {
    $asked = new ReportFilters(CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addDay(), agentId: $this->senderB->id, accountType: LedgerAccountType::AgentSend->value);

    expect(app(ReportRegistry::class)->find('agent-statement')->rows($asked, $this->senderA))->toHaveCount(0);
});

it('shows a sending agent only their own credit', function () {
    $ledger = app(LedgerService::class);
    $funder = makeAdmin();

    foreach ([[$this->senderA, 5000], [$this->senderB, 9000]] as [$agent, $cents]) {
        $ledger->post(JournalType::TopupCredit, [
            [$ledger->accountFor($agent, LedgerAccountType::AgentCredit), $cents],
            [$ledger->accountFor($agent, LedgerAccountType::AgentSend), -$cents],
        ], null, $funder);
    }

    $mine = ownRows('credit', $this->senderA);

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['agent'])->toBe($this->senderA->name)
        ->and($mine[0]['outstanding'])->toBe(5000)
        ->and(ownRows('credit', makeAdmin()))->toHaveCount(2);
});

it('shows only their own cancellations', function () {
    $service = app(TransferService::class);
    $cancel = app(TransferCancellationService::class);

    foreach ([$this->senderA, $this->senderB] as $agent) {
        $t = $service->create($agent, $this->branch, H::transferPayload(['amount_cents' => 1000]), (string) Str::uuid());
        $cancel->cancel($t, makeAdmin(), 'changed mind by '.$agent->id);
    }

    $rows = ownRows('cancellations', $this->senderA);

    expect($rows)->toHaveCount(1)->and($rows[0]['reason'])->toContain((string) $this->senderA->id);
});

it('keeps other people’s names out of an agent’s report filters', function () {
    $this->actingAs($this->senderA)->get('/reports/transfers')->assertOk()
        ->assertSee($this->senderA->name)
        ->assertDontSee($this->senderB->name)
        ->assertDontSee($this->payerB->name);

    $this->actingAs(makeAdmin())->get('/reports/transfers')->assertOk()->assertSee($this->senderB->name);
});

it('gives each kind of agent a useful set of reports and none of the company-wide ones', function () {
    $registry = app(ReportRegistry::class);

    $sender = $registry->visibleTo($this->senderA)->map->key()->all();
    $payer = $registry->visibleTo($this->payerA)->map->key()->all();

    expect($sender)->toContain('transfers', 'commissions', 'agent-statement', 'credit', 'uncollected', 'cancellations', 'customers')
        ->and($payer)->toContain('transfers', 'commissions', 'agent-statement', 'payouts-by-method', 'cancellations')
        ->and(array_merge($sender, $payer))->not->toContain('profit', 'company-accounts', 'branch-summary');
});

// --- Item 8: receipt -------------------------------------------------------------

it('has PDF receipts on by default and a download button on the transfer', function () {
    expect(app(ReceiptService::class)->enabled())->toBeTrue();

    $this->actingAs($this->senderA)->get(route('transfers.show', $this->tA))->assertOk()->assertSee('Download receipt');

    $response = $this->actingAs($this->senderA)->get(route('transfers.receipt', $this->tA));
    $response->assertOk()->assertDownload("receipt-{$this->tA->code}.pdf");
    expect($response->getContent())->toStartWith('%PDF-');
});

it('can still be switched off, and other agents cannot fetch it', function () {
    $this->actingAs($this->senderB)->get(route('transfers.receipt', $this->tA))->assertForbidden();

    Settings::set('receipts.enabled', false);
    $this->actingAs($this->senderA)->get(route('transfers.receipt', $this->tA))->assertNotFound();
});

it('prints the tracking number and both locations on the receipt', function () {
    $html = view('transfers.receipt', [
        'transfer' => $this->tA->load(['branch', 'sendingAgent', 'paidBy', 'payoutAccount.accountType', 'receiverAccountType']),
        'companyName' => 'Blue Star', 'logo' => null, 'timezone' => 'UTC',
    ])->render();

    expect($html)->toContain('Tracking number')->toContain($this->tA->code)
        ->toContain('Sweden')->toContain('Stockholm')
        ->toContain('Somalia')->toContain('Mogadishu')->toContain('Banadir');
});

it('puts the places in the WhatsApp text too', function () {
    $text = app(ReceiptService::class)->whatsappText($this->tA);

    expect($text)->toContain('Tracking number: '.$this->tA->code)
        ->toContain('Stockholm, Sweden')->toContain('Mogadishu, Somalia');
});

it('shows the locations on the transfer page', function () {
    $this->actingAs($this->senderA)->get(route('transfers.show', $this->tA))->assertOk()
        ->assertSee('Stockholm, Sweden')->assertSee('Mogadishu, Banadir, Somalia');
});
