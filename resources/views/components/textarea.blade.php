@props(['label' => null, 'name', 'hint' => null, 'rows' => 3, 'value' => null])

@php
$hasError = $errors->has($name);
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $label }}</label>
    @endif

    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border py-2.5 px-3.5 text-sm shadow-sm transition
                focus:outline-none focus:ring-2 focus:ring-offset-0
                '.($hasError
                    ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                    : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100'),
        ]) }}
    >{{ old($name, $value) }}</textarea>

    @if ($hint && !$hasError)
        <p class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
