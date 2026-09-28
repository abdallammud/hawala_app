@props(['type' => 'info', 'title' => null, 'dismissible' => false])

@php
$types = [
    'info' => ['bg-sky-50 border-sky-200 text-sky-800', 'information-circle', 'text-sky-500'],
    'success' => ['bg-emerald-50 border-emerald-200 text-emerald-800', 'check-circle', 'text-emerald-500'],
    'warning' => ['bg-amber-50 border-amber-200 text-amber-800', 'exclamation-triangle', 'text-amber-500'],
    'error' => ['bg-red-50 border-red-200 text-red-800', 'x-circle', 'text-red-500'],
];
[$classes, $icon, $iconColor] = $types[$type] ?? $types['info'];
@endphp

<div
    @if ($dismissible) x-data="{ show: true }" x-show="show" @endif
    {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-lg border p-4 text-sm '.$classes]) }}
>
    <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $iconColor }}" />
    <div class="flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" x-on:click="show = false" class="shrink-0 {{ $iconColor }} hover:opacity-70">
            <x-icon name="x-mark" class="w-4 h-4" />
        </button>
    @endif
</div>
