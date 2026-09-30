@props(['label', 'value', 'icon' => null, 'tone' => 'brand', 'trend' => null])

@php
// Each tone tints the whole card, not just the icon, so a dashboard reads at
// a glance: brand = money we hold, gold = commission, amber/red = needs
// attention, green = earned.
$tones = [
    'brand' => ['card' => 'bg-brand-50 border-brand-200', 'icon' => 'bg-brand-600 text-white', 'value' => 'text-brand-900', 'label' => 'text-brand-700'],
    'gold' => ['card' => 'bg-gold-50 border-gold-200', 'icon' => 'bg-gold-500 text-white', 'value' => 'text-gold-900', 'label' => 'text-gold-700'],
    'green' => ['card' => 'bg-emerald-50 border-emerald-200', 'icon' => 'bg-emerald-600 text-white', 'value' => 'text-emerald-900', 'label' => 'text-emerald-700'],
    'amber' => ['card' => 'bg-amber-50 border-amber-200', 'icon' => 'bg-amber-500 text-white', 'value' => 'text-amber-900', 'label' => 'text-amber-700'],
    'red' => ['card' => 'bg-red-50 border-red-200', 'icon' => 'bg-red-600 text-white', 'value' => 'text-red-900', 'label' => 'text-red-700'],
    'slate' => ['card' => 'bg-white border-slate-200', 'icon' => 'bg-slate-500 text-white', 'value' => 'text-slate-900', 'label' => 'text-slate-500'],
];

$t = $tones[$tone] ?? $tones['brand'];
@endphp

<div class="rounded-xl border shadow-sm p-5 flex items-start justify-between gap-4 {{ $t['card'] }}">
    <div class="min-w-0">
        <p class="text-xs font-semibold uppercase tracking-wide truncate {{ $t['label'] }}">{{ $label }}</p>
        <p class="mt-2 text-2xl font-bold tracking-tight truncate {{ $t['value'] }}">{{ $value }}</p>
        @if ($trend)
            <p class="mt-1 text-xs {{ $t['label'] }} opacity-80">{{ $trend }}</p>
        @endif
    </div>
    @if ($icon)
        <div class="shrink-0 rounded-lg p-2.5 shadow-sm {{ $t['icon'] }}">
            <x-icon :name="$icon" class="w-5 h-5" />
        </div>
    @endif
</div>
