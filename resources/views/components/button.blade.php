@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button', 'href' => null])

@php
$base = 'inline-flex items-center justify-center gap-2 font-semibold rounded-lg transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

$sizes = [
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2.5 text-sm',
    'lg' => 'px-5 py-3 text-base',
];

$variants = [
    'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:ring-brand-500 shadow-sm',
    'gold' => 'bg-gold-500 text-white hover:bg-gold-600 focus-visible:ring-gold-400 shadow-sm',
    'secondary' => 'bg-white text-brand-700 border border-slate-300 hover:bg-slate-50 focus-visible:ring-brand-500',
    'danger' => 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500 shadow-sm',
    'ghost' => 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-400',
];

$classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
