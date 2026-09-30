@props(['label' => null, 'name', 'options' => [], 'hint' => null, 'placeholder' => null, 'value' => null])

@php
$hasError = $errors->has($name);
$selected = old($name, $value);
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $label }}</label>
    @endif

    <select
        id="{{ $name }}"
        name="{{ $name }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border py-3 text-base sm:py-2.5 sm:text-sm pl-3.5 pr-10 shadow-sm transition
                focus:outline-none focus:ring-2 focus:ring-offset-0
                '.($hasError
                    ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                    : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100'),
        ]) }}
    >
        @if ($placeholder)
            <option value="" @selected($selected === '')>{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected((string) $selected === (string) $optValue)>{{ $optLabel }}</option>
        @endforeach
    </select>

    @if ($hint && !$hasError)
        <p class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
