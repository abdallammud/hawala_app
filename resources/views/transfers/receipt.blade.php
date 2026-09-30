{{-- Standalone document: dompdf gets no Tailwind, so the styles are inline. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $transfer->code }}</title>
    <style>
        @page { margin: 28px 34px; }
        /* Helvetica is a PDF built-in: nothing to embed, so a receipt stays a
           few tens of KB instead of ~900 KB with DejaVu. Somali is Latin-script. */
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 2px solid #29468a; padding-bottom: 12px; margin-bottom: 18px; }
        .header td { vertical-align: middle; border: 0; padding: 0; }
        .company { font-size: 17px; font-weight: bold; color: #121e3b; }
        .muted { color: #6b7280; }
        .code-box { background: #f3f6fb; border: 1px solid #c0cdec; padding: 10px 12px; margin-bottom: 16px; }
        .code { font-size: 20px; font-weight: bold; color: #121e3b; letter-spacing: 1px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #29468a;
             margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e5e7eb; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 4px 0; vertical-align: top; }
        table.data td.label { color: #6b7280; width: 38%; }
        table.data td.value { font-weight: bold; }
        table.amounts { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.amounts td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        table.amounts td.num { text-align: right; font-weight: bold; }
        table.amounts tr.total td { border-bottom: 0; border-top: 2px solid #29468a; font-size: 13px; }
        .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e5e7eb;
                  font-size: 9px; color: #9ca3af; text-align: center; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #c0cdec;
                 background: #f3f6fb; color: #29468a; font-size: 10px; }
    </style>
</head>
<body>

    <table class="header" width="100%">
        <tr>
            @if ($logo)
                <td width="52"><img src="{{ $logo }}" width="44" height="44" alt=""></td>
            @endif
            <td>
                <div class="company">{{ $companyName }}</div>
                <div class="muted">{{ __('Transfer receipt') }}</div>
            </td>
            <td align="right" class="muted">
                {{ __('Issued') }}: {{ now()->timezone($timezone)->format('Y-m-d H:i') }}
            </td>
        </tr>
    </table>

    <div class="code-box">
        <table width="100%">
            <tr>
                <td>
                    <div class="muted">{{ __('Reference') }}</div>
                    <div class="code">{{ $transfer->code }}</div>
                </td>
                <td align="right">
                    <span class="badge">{{ $transfer->status->label() }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table width="100%">
        <tr>
            <td width="48%" valign="top">
                <h2>{{ __('Sender') }}</h2>
                <table class="data">
                    <tr><td class="label">{{ __('Name') }}</td><td class="value">{{ $transfer->sender_name }}</td></tr>
                    <tr><td class="label">{{ __('Phone') }}</td><td class="value">{{ $transfer->sender_phone ?: '—' }}</td></tr>
                    <tr><td class="label">{{ __('Agent') }}</td><td class="value">{{ $transfer->sendingAgent?->name ?? '—' }}</td></tr>
                    <tr><td class="label">{{ __('Date') }}</td><td class="value">{{ $transfer->created_at->timezone($timezone)->format('Y-m-d H:i') }}</td></tr>
                </table>
            </td>
            <td width="4%"></td>
            <td width="48%" valign="top">
                <h2>{{ __('Receiver') }}</h2>
                <table class="data">
                    <tr><td class="label">{{ __('Name') }}</td><td class="value">{{ $transfer->receiver_name }}</td></tr>
                    <tr><td class="label">{{ __('Phone') }}</td><td class="value">{{ $transfer->receiver_phone ?: '—' }}</td></tr>
                    <tr><td class="label">{{ __('Branch') }}</td><td class="value">{{ $transfer->branch?->name ?? '—' }}</td></tr>
                    <tr>
                        <td class="label">{{ __('Pay by') }}</td>
                        <td class="value">
                            {{ $transfer->receiverAccountType?->name ?? __('Cash') }}
                            @if ($transfer->receiver_account_number)
                                <br><span class="muted">{{ $transfer->receiver_account_number }}</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <h2>{{ __('Amounts') }}</h2>
    <table class="amounts">
        @if ($transfer->original_currency !== 'USD')
            <tr>
                <td>{{ __('Collected from sender') }} ({{ $transfer->original_currency }})</td>
                <td class="num">{{ \App\Support\Money::format($transfer->collect_original_cents, $transfer->original_currency) }}</td>
            </tr>
            <tr>
                <td class="muted">{{ __('Exchange rate applied') }}</td>
                <td class="num muted">{{ rtrim(rtrim((string) $transfer->customer_rate, '0'), '.') }}</td>
            </tr>
        @endif
        <tr>
            <td>{{ __('Amount to receiver') }}</td>
            <td class="num">{{ \App\Support\Money::format($transfer->amount_cents) }}</td>
        </tr>
        <tr>
            <td>{{ __('Fee') }}</td>
            <td class="num">{{ \App\Support\Money::format($transfer->fee_cents) }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('Total') }}</td>
            <td class="num">{{ \App\Support\Money::format($transfer->total_debit_cents) }}</td>
        </tr>
    </table>

    @if ($transfer->status === \App\Enums\TransferStatus::Paid)
        <h2>{{ __('Payment') }}</h2>
        <table class="data">
            <tr><td class="label">{{ __('Paid on') }}</td><td class="value">{{ $transfer->paid_at?->timezone($timezone)->format('Y-m-d H:i') ?? '—' }}</td></tr>
            <tr><td class="label">{{ __('Paid by') }}</td><td class="value">{{ $transfer->paidBy?->name ?? '—' }}</td></tr>
            <tr>
                <td class="label">{{ __('Paid via') }}</td>
                <td class="value">
                    {{ $transfer->payoutAccount?->accountType?->name ?? '—' }}
                    @if ($transfer->payout_reference)
                        <br><span class="muted">{{ __('Ref') }}: {{ $transfer->payout_reference }}</span>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <div class="footer">
        {{ __('This receipt was generated by :company. Keep the reference :code for any enquiry.', ['company' => $companyName, 'code' => $transfer->code]) }}
    </div>

</body>
</html>
