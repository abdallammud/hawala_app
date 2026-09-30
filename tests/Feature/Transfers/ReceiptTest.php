<?php

use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\ReceiptService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();

    $this->agent = H::sendingAgent(fundCents: 500000);
    $this->transfer = app(TransferService::class)->create(
        $this->agent, $this->branch, H::transferPayload(['amount_cents' => 25000]), (string) Str::uuid()
    );
});

it('hides receipts entirely while receipts.enabled is off', function () {
    Settings::set('receipts.enabled', false);

    $this->actingAs($this->agent)
        ->get(route('transfers.show', $this->transfer))
        ->assertOk()
        ->assertDontSee(__('Download receipt'));

    $this->actingAs($this->agent)
        ->get(route('transfers.receipt', $this->transfer))
        ->assertNotFound();
});

it('offers the receipt once it is switched on', function () {
    Settings::set('receipts.enabled', true);

    $this->actingAs($this->agent)
        ->get(route('transfers.show', $this->transfer))
        ->assertOk()
        ->assertSee(__('Download receipt'))
        ->assertSee(__('Share via WhatsApp'));
});

it('downloads a real PDF naming the transfer', function () {
    Settings::set('receipts.enabled', true);

    $response = $this->actingAs($this->agent)->get(route('transfers.receipt', $this->transfer));

    $response->assertOk()
        ->assertDownload("receipt-{$this->transfer->code}.pdf");

    // A PDF, not an HTML error page.
    expect($response->getContent())->toStartWith('%PDF-');
});

it('does not let an agent download someone else transfer receipt', function () {
    Settings::set('receipts.enabled', true);

    $other = H::sendingAgent(fundCents: 1000);

    $this->actingAs($other)
        ->get(route('transfers.receipt', $this->transfer))
        ->assertForbidden();
});

it('builds a WhatsApp link carrying the key details', function () {
    $service = app(ReceiptService::class);

    $text = $service->whatsappText($this->transfer);

    expect($text)->toContain($this->transfer->code)
        ->toContain($this->transfer->receiver_name)
        ->toContain('$250.00');

    $url = $service->whatsappUrl($this->transfer);

    expect($url)->toStartWith('https://wa.me/?text=')
        // Everything after the marker must be encoded, so no raw newlines leak.
        ->and($url)->not->toContain("\n")
        ->and(urldecode(str_replace('https://wa.me/?text=', '', $url)))->toContain($this->transfer->code);
});

it('shows the payment details on a paid transfer receipt', function () {
    Settings::set('receipts.enabled', true);

    $payer = H::payoutAgent($this->branch, fundCents: 500000);
    $queue = app(PayoutQueueService::class);
    $queue->claim($this->transfer, $payer);
    $paid = $queue->markPaid($this->transfer->fresh(), $payer, H::payoutAccountFor($payer), 'REF-99');

    // Render the underlying view rather than the binary so the content is checkable.
    $html = view('transfers.receipt', [
        'transfer' => $paid->fresh(),
        'companyName' => 'Blue Star',
        'logo' => null,
        'timezone' => 'Africa/Mogadishu',
    ])->render();

    expect($html)->toContain($paid->code)
        ->toContain('REF-99')
        ->toContain($payer->name)
        ->toContain('$250.00');
});

it('renders a non-USD receipt with the collected amount and rate', function () {
    $eurTransfer = app(TransferService::class)->create(
        $this->agent,
        $this->branch,
        H::transferPayload(['currency' => 'EUR', 'amount_cents' => 10000]),
        (string) Str::uuid()
    );

    $html = view('transfers.receipt', [
        'transfer' => $eurTransfer,
        'companyName' => 'Blue Star',
        'logo' => null,
        'timezone' => 'Africa/Mogadishu',
    ])->render();

    expect($html)->toContain('EUR')
        ->toContain(__('Exchange rate applied'));
});
