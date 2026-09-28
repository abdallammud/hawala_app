@props(['color' => 'slate'])

@php
$colors = [
    'slate' => 'bg-slate-100 text-slate-700',
    'brand' => 'bg-brand-50 text-brand-700',
    'gold' => 'bg-gold-50 text-gold-700',
    'green' => 'bg-emerald-50 text-emerald-700',
    'red' => 'bg-red-50 text-red-700',
    'amber' => 'bg-amber-50 text-amber-700',
    'blue' => 'bg-sky-50 text-sky-700',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium '.($colors[$color] ?? $colors['slate'])]) }}>
    {{ $slot }}
</span>
