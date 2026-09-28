@props(['cents', 'currency' => 'USD', 'colorize' => false])

@php
$cents = (int) $cents;
$color = '';
if ($colorize) {
    $color = $cents < 0 ? 'text-red-600' : 'text-slate-900';
}
@endphp

<span {{ $attributes->merge(['class' => 'font-mono tabular-nums '.$color]) }}>
    {{ \App\Support\Money::format($cents, $currency) }}
</span>
