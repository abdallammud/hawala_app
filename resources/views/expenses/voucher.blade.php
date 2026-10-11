{{-- Standalone document: dompdf gets no Tailwind, so the styles are inline. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Expense voucher') }} {{ $expense->number }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 2px solid #29468a; padding-bottom: 12px; margin-bottom: 18px; }
        .header td { vertical-align: middle; border: 0; padding: 0; }
        .company { font-size: 17px; font-weight: bold; color: #121e3b; }
        .muted { color: #6b7280; }
        .code-box { background: #f3f6fb; border: 1px solid #c0cdec; padding: 10px 12px; margin-bottom: 16px; }
        .code { font-size: 20px; font-weight: bold; color: #121e3b; letter-spacing: 1px; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #c0cdec; background: #f3f6fb; color: #29468a; font-size: 10px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #29468a;
             margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e5e7eb; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 5px 0; vertical-align: top; }
        table.data td.label { color: #6b7280; width: 30%; }
        table.data td.value { font-weight: bold; }
        .amount { font-size: 22px; font-weight: bold; color: #121e3b; }
        .notice { border: 1px solid #f3d9a4; background: #fff8e8; color: #8a5a00; padding: 8px 10px; margin: 12px 0; }
        table.sign { width: 100%; margin-top: 54px; border-collapse: collapse; }
        table.sign td { width: 31%; border-top: 1px solid #9ca3af; padding-top: 5px; text-align: center; color: #6b7280; font-size: 10px; }
        table.sign td.gap { width: 3.5%; border-top: 0; }
        .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; }
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
                <div class="muted">{{ __('Expense voucher') }}</div>
            </td>
            <td align="right" class="muted">
                {{ __('Printed') }}: {{ now()->timezone($timezone)->format('Y-m-d H:i') }}
            </td>
        </tr>
    </table>

    <div class="code-box">
        <table width="100%">
            <tr>
                <td>
                    <div class="muted">{{ __('Voucher number') }}</div>
                    <div class="code">{{ $expense->number }}</div>
                </td>
                <td align="right">
                    <span class="badge">{{ $expense->status->label() }}</span>
                </td>
            </tr>
        </table>
    </div>

    @if ($expense->isPending())
        <div class="notice">{{ __('Not approved yet. This voucher is not valid for payment until it has been approved.') }}</div>
    @elseif ($expense->isRejected())
        <div class="notice">{{ __('Rejected. No payment was made.') }}</div>
    @endif

    <h2>{{ __('Amount') }}</h2>
    <div class="amount">{{ \App\Support\Money::format($expense->amount_cents) }}</div>
    @if ($expense->original_currency !== 'USD')
        <div class="muted">
            {{ \App\Support\Money::format($expense->original_amount_cents, $expense->original_currency) }}
            @ {{ rtrim(rtrim((string) $expense->rate, '0'), '.') }}
        </div>
    @endif

    <h2>{{ __('Details') }}</h2>
    <table class="data">
        <tr><td class="label">{{ __('Expense type') }}</td><td class="value">{{ $expense->type->name }}</td></tr>
        <tr><td class="label">{{ __('Date') }}</td><td class="value">{{ $expense->expense_date->format('Y-m-d') }}</td></tr>
        <tr><td class="label">{{ __('Paid to') }}</td><td class="value">{{ $expense->payee ?: '—' }}</td></tr>
        <tr><td class="label">{{ __('Reference') }}</td><td class="value">{{ $expense->reference ?: '—' }}</td></tr>
        <tr><td class="label">{{ __('Paid from') }}</td><td class="value">{{ $expense->companyAccount->name }}</td></tr>
        <tr><td class="label">{{ __('Branch') }}</td><td class="value">{{ $expense->branch?->name ?? '—' }}</td></tr>
        @if ($expense->description)
            <tr><td class="label">{{ __('Note') }}</td><td class="value">{{ $expense->description }}</td></tr>
        @endif
    </table>

    <h2>{{ __('Approval') }}</h2>
    <table class="data">
        <tr>
            <td class="label">{{ __('Recorded by') }}</td>
            <td class="value">{{ $expense->createdBy->name }} <span class="muted">· {{ $expense->created_at->timezone($timezone)->format('Y-m-d H:i') }}</span></td>
        </tr>
        <tr>
            <td class="label">{{ $expense->status->label() }}</td>
            <td class="value">
                @if ($expense->isPending())
                    —
                @else
                    {{ $expense->decidedBy?->name ?? __('the system') }}
                    <span class="muted">· {{ $expense->decided_at?->timezone($timezone)->format('Y-m-d H:i') }}</span>
                @endif
            </td>
        </tr>
        @if ($expense->decision_reason)
            <tr><td class="label">{{ __('Reason') }}</td><td class="value">{{ $expense->decision_reason }}</td></tr>
        @endif
    </table>

    <table class="sign">
        <tr>
            <td>{{ __('Prepared by') }}</td>
            <td class="gap"></td>
            <td>{{ __('Approved by') }}</td>
            <td class="gap"></td>
            <td>{{ __('Received by') }}</td>
        </tr>
    </table>

    <div class="footer">{{ $companyName }} · {{ $expense->number }}</div>
</body>
</html>
