@props(['label', 'value', 'icon' => null, 'tone' => 'brand', 'trend' => null])

@php
$tones = [
    'brand' => 'bg-brand-50 text-brand-600',
    'gold' => 'bg-gold-50 text-gold-600',
    'green' => 'bg-emerald-50 text-emerald-600',
    'red' => 'bg-red-50 text-red-600',
    'slate' => 'bg-slate-100 text-slate-600',
];
@endphp

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 flex items-start justify-between gap-4">
    <div class="min-w-0">
        <p class="text-xs font-medium text-slate-500 truncate">{{ $label }}</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900 tracking-tight truncate">{{ $value }}</p>
        @if ($trend)
            <p class="mt-1 text-xs text-slate-500">{{ $trend }}</p>
        @endif
    </div>
    @if ($icon)
        <div class="shrink-0 rounded-lg p-2.5 {{ $tones[$tone] ?? $tones['brand'] }}">
            <x-icon :name="$icon" class="w-5 h-5" />
        </div>
    @endif
</div>
