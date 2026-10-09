<?php

namespace App\Services\Transfers;

use App\Models\Transfer;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

class ReceiptService
{
    public function enabled(): bool
    {
        return (bool) Settings::get('receipts.enabled', false);
    }

    public function filename(Transfer $transfer): string
    {
        return "receipt-{$transfer->code}.pdf";
    }

    public function pdf(Transfer $transfer): PdfDocument
    {
        $transfer->loadMissing(['branch', 'sendingAgent', 'paidBy', 'payoutAccount.accountType', 'receiverAccountType']);

        return Pdf::loadView('transfers.receipt', [
            'transfer' => $transfer,
            'companyName' => Settings::get('general.company_name', config('app.name')),
            'logo' => $this->logoData(),
            'timezone' => Settings::get('general.timezone', 'Africa/Mogadishu'),
        ])->setPaper('a4');
    }

    /**
     * A plain-text summary for WhatsApp. No API needed — it becomes a
     * https://wa.me/?text= link the agent taps (BUILD.md §13).
     */
    public function whatsappText(Transfer $transfer): string
    {
        $company = Settings::get('general.company_name', config('app.name'));
        $tz = Settings::get('general.timezone', 'Africa/Mogadishu');

        $lines = [
            "*{$company}* — ".__('Transfer receipt'),
            '',
            __('Tracking number').": {$transfer->code}",
            __('Date').': '.$transfer->created_at->timezone($tz)->format('Y-m-d H:i'),
            __('Sender').": {$transfer->sender_name}".$this->place($transfer->sender_city, $transfer->sender_country),
            __('Receiver').": {$transfer->receiver_name} ({$transfer->receiver_phone})".$this->place($transfer->receiver_city, $transfer->receiver_country),
            __('Branch').': '.($transfer->branch?->name ?? '—'),
            __('Amount').': '.Money::format($transfer->amount_cents),
            __('Fee').': '.Money::format($transfer->fee_cents),
        ];

        if ($transfer->original_currency !== 'USD') {
            $lines[] = __('Collected').': '.Money::format($transfer->collect_original_cents, $transfer->original_currency);
        }

        $lines[] = __('Status').': '.$transfer->status->label();

        return implode("\n", $lines);
    }

    /** " — Mogadishu, Somalia" or nothing. */
    private function place(?string $city, ?string $country): string
    {
        $place = collect([$city, $country])->filter()->implode(', ');

        return $place === '' ? '' : ' — '.$place;
    }

    public function whatsappUrl(Transfer $transfer): string
    {
        // No number: WhatsApp asks the agent to pick a contact.
        return 'https://wa.me/?text='.rawurlencode($this->whatsappText($transfer));
    }

    /**
     * The logo as a data URI. dompdf resolves remote URLs unreliably on
     * shared hosting, so the bytes are embedded instead.
     *
     * Prefers the small receipt-sized copy: the full logo-mark is 862px and
     * 400 KB, which base64-encoded turned every one-page receipt into a
     * 1.3 MB download for an agent to share over mobile data.
     */
    private function logoData(): ?string
    {
        foreach (['images/logo-receipt.png', 'images/logo-mark.png'] as $candidate) {
            $path = public_path($candidate);

            if (is_file($path)) {
                return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }
}
