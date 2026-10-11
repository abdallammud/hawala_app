{{--
    The one print / PDF layout for every report. Standalone document, tables
    only and inline styles, so dompdf and the browser's print dialog render it
    the same way. $mode is 'print' (browser) or 'pdf' (dompdf).

    The header (logo, company, title, filters, who and when) sits inside <thead>,
    so it repeats on every page together with the column headings. Page numbers:
    drawn on the PDF by ReportsController once the page count is known, @page margin boxes for the browser (Chrome
    and Edge; other browsers print without them).
--}}
@php
    $columns = $report->columns();
    $money = $report->moneyColumns();
    $numeric = $report->numericColumns();
    $colspan = count($columns);
    $isPdf = $mode === 'pdf';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title() }} — {{ $companyName }}</title>
    <style>
        @page { margin: {{ $isPdf ? '30px 30px 46px 30px' : '14mm 12mm 16mm 12mm' }}; }
        @if (! $isPdf)
            @page {
                @bottom-left { content: "{{ $companyName }} · {{ $report->title() }}"; font: 8px Helvetica, Arial, sans-serif; color: #6b7280; }
                @bottom-right { content: "{{ __('Page') }} " counter(page) " / " counter(pages); font: 8px Helvetica, Arial, sans-serif; color: #6b7280; }
            }
        @endif
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9.5px; color: #1f2937; margin: 0; }
        table { border-collapse: separate; border-spacing: 0; width: 100%; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .head-cell { padding: 0 0 8px 0; border: 0; text-align: left; font-weight: normal; }
        .head-cell table td { border: 0; padding: 0; vertical-align: middle; }
        .company { font-size: 14px; font-weight: bold; color: #121e3b; }
        .title { font-size: 12px; font-weight: bold; color: #29468a; margin-top: 2px; }
        .meta { color: #6b7280; font-size: 8.5px; text-align: right; line-height: 1.5; }
        .filters { color: #374151; font-size: 8.5px; padding-top: 6px; line-height: 1.5; }
        .filters b { color: #6b7280; font-weight: normal; }
        .rule { border-top: 2px solid #29468a; height: 0; font-size: 0; margin-top: 6px; }
        th.col { background: #f3f6fb; color: #374151; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px;
                 padding: 5px 6px; border-bottom: 1px solid #c0cdec; text-align: left; white-space: nowrap; }
        td.cell { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; border-top: 2px solid #29468a; border-bottom: 0; padding-top: 6px; background: #f9fafb; }
        tr.sum td { color: #4b5563; border-bottom: 1px solid #f3f4f6; padding: 3px 6px; }
        .empty { padding: 30px; text-align: center; color: #6b7280; }
        .toolbar { background: #121e3b; color: #fff; padding: 10px 14px; font-family: Helvetica, Arial, sans-serif; font-size: 13px; }
        .toolbar button, .toolbar a { background: #fff; color: #121e3b; border: 0; border-radius: 6px; padding: 7px 14px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; margin-right: 8px; }
        @media print { .toolbar { display: none; } }
        @media screen { body.print-view { max-width: 1100px; margin: 0 auto; padding: 16px; background: #fff; } }
    </style>
</head>
<body class="{{ $isPdf ? '' : 'print-view' }}">
    @unless ($isPdf)
        <div class="toolbar">
            <button type="button" onclick="window.print()">{{ __('Print') }}</button>
            <a href="{{ route('reports.pdf', array_merge([$report->key()], request()->query())) }}">{{ __('Download PDF') }}</a>
            <a href="javascript:window.close()">{{ __('Close') }}</a>
        </div>
    @endunless

    <table>
        <thead>
            <tr>
                <th class="head-cell" colspan="{{ $colspan }}">
                    <table>
                        <tr>
                            @if ($logo)
                                <td width="44"><img src="{{ $logo }}" width="38" height="38" alt=""></td>
                            @endif
                            <td>
                                <div class="company">{{ $companyName }}</div>
                                <div class="title">{{ $report->title() }}</div>
                            </td>
                            <td class="meta">
                                {{ __('Generated') }}: {{ $generatedAt }}<br>
                                {{ __('By') }}: {{ $generatedBy }}
                            </td>
                        </tr>
                    </table>
                    @if (count($filtersUsed))
                        <div class="filters">
                            @foreach ($filtersUsed as [$label, $value])
                                <b>{{ $label }}:</b> {{ $value }}@unless ($loop->last) &nbsp;·&nbsp; @endunless
                            @endforeach
                        </div>
                    @endif
                    <div class="rule">&nbsp;</div>
                </th>
            </tr>
            <tr>
                @foreach ($columns as $key => $label)
                    <th class="col {{ in_array($key, $numeric) ? 'num' : '' }}">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            {{-- One line per row, no stray whitespace: dompdf builds a frame for every text node, and thousands of them cost memory. --}}
            @forelse ($rows as $row)
                <tr>@foreach ($columns as $key => $label)<td class="cell{{ in_array($key, $numeric) ? ' num' : '' }}">{{ in_array($key, $money) ? \App\Support\Money::format((int) ($row[$key] ?? 0)) : ($row[$key] ?? '') }}</td>@endforeach</tr>
            @empty
                <tr><td class="empty" colspan="{{ $colspan }}">{{ __('Nothing in this range.') }}</td></tr>
            @endforelse

            @if ($rows->isNotEmpty() && ! empty($totals))
                <tr class="total">
                    @foreach ($columns as $key => $label)
                        <td class="{{ in_array($key, $numeric) ? 'num' : '' }}">
                            @if (array_key_exists($key, $totals))
                                {{ in_array($key, $money) ? \App\Support\Money::format((int) $totals[$key]) : $totals[$key] }}
                            @elseif ($loop->first)
                                {{ $totalLabel }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endif

            @foreach ($summary as [$label, $cents])
                <tr class="sum">
                    <td colspan="{{ max(1, $colspan - 1) }}" class="num">{{ $label }}</td>
                    <td class="num">{{ \App\Support\Money::format($cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($rows->isNotEmpty())
        <p style="color:#6b7280; font-size: 8.5px; margin-top: 8px;">{{ trans_choice(':count row|:count rows', $rows->count(), ['count' => $rows->count()]) }}</p>
    @endif

    @unless ($isPdf)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endunless
</body>
</html>
