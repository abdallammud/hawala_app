@props(['label' => null, 'name', 'type' => 'text', 'hint' => null, 'prefix' => null, 'value' => null])

@php
$hasError = $errors->has($name);
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $label }}</label>
    @endif

    <div class="relative">
        @if ($prefix)
            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">{{ $prefix }}</span>
        @endif

        <input
            id="{{ $name }}"
            name="{{ $name }}"
            type="{{ $type }}"
            {{ $attributes->merge([
                // text-base on a phone is deliberate: iOS Safari zooms the whole
                // page when you focus an input smaller than 16px. py-3 keeps the
                // tap target at 44px for agents working at a counter.
                'class' => 'block w-full rounded-lg border py-3 text-base sm:py-2.5 sm:text-sm shadow-sm transition
                    focus:outline-none focus:ring-2 focus:ring-offset-0
                    '.($prefix ? 'pl-8' : 'pl-3.5').' pr-3.5 '.
                    ($hasError
                        ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                        : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100'),
            ]) }}
            value="{{ old($name, $value) }}"
        />
    </div>

    @if ($hint && !$hasError)
        <p class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
