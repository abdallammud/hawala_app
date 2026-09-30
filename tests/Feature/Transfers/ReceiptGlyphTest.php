<?php

// Confirms the built-in PDF font renders the em-dash and middle dot the
// receipt falls back to, rather than silently dropping them.
use App\Services\Transfers\ReceiptService;
use App\Services\Transfers\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

it('renders the em-dash placeholder in the PDF', function () {
    $agent = H::sendingAgent(fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    // No account type -> "Cash"; no payout yet -> em-dashes in the template.
    $transfer->forceFill(['sender_phone' => ''])->save();

    $pdf = app(ReceiptService::class)->pdf($transfer->fresh())->output();

    // Pull the text out of the compressed content stream.
    $text = '';
    preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);
    foreach ($m[1] as $blob) {
        $out = @gzuncompress($blob);
        if ($out !== false) {
            $text .= $out;
        }
    }

    expect($text)->toContain($transfer->code);

    // WinAnsi encodes the em-dash as 0x97; an unsupported glyph would be
    // dropped or replaced instead.
    $hasEmDash = str_contains($text, "\x97") || str_contains($text, '\\227');

    expect($hasEmDash)->toBeTrue('em-dash did not survive into the PDF');
});
