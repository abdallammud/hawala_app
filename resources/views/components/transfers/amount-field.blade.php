@props([
    'name',
    'currencyName',
    'label',
    'currencies' => [],
    'amountModel',
    'currencyModel',
    'typedMode',
    'locked' => false,
])

{{--
    One leg of a transfer: an amount and the currency it is in, side by side.
    Typing here makes this leg the one the agent meant (`typedMode`) and the
    other leg refills itself; changing the currency re-quotes the same leg.
--}}
@php
    $hasError = $errors->has($name) || $errors->has($currencyName);
@endphp

{{--
    `locked` is the "Customer pays" box when customers always pay in USD: the
    amount is shown but cannot be typed, and the currency is fixed. A disabled
    select is not submitted, so the fixed currency also travels as a hidden
    input; the server forces USD regardless (StoreTransferRequest).
--}}
<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 mb-1.5">{{ $label }}</label>

    <div class="flex gap-2">
        <input
            id="{{ $name }}"
            name="{{ $name }}"
            type="number"
            inputmode="decimal"
            min="0.01"
            step="0.01"
            placeholder="0.00"
            x-model="{{ $amountModel }}"
            @if ($locked)
                readonly
                tabindex="-1"
                aria-readonly="true"
            @else
                x-on:input.debounce.400ms="typedIn('{{ $typedMode }}')"
            @endif
            value="{{ old($name) }}"
            class="flex-1 min-w-0 rounded-lg border py-3 text-base sm:py-2.5 sm:text-sm px-3.5 shadow-sm transition
                font-mono focus:outline-none focus:ring-2
                {{ $locked
                    ? 'border-slate-200 bg-slate-100 text-slate-700 cursor-not-allowed focus:border-slate-200 focus:ring-0'
                    : ($hasError
                        ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                        : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100') }}"
        />

        @if ($locked)
            <input type="hidden" name="{{ $currencyName }}" value="USD">
            <select
                id="{{ $currencyName }}"
                disabled
                aria-readonly="true"
                class="w-28 shrink-0 rounded-lg border border-slate-200 bg-slate-100 py-3 text-base sm:py-2.5 sm:text-sm pl-3 pr-8 shadow-sm text-slate-700 cursor-not-allowed"
            >
                <option value="USD" selected>USD</option>
            </select>
        @else
        <select
            id="{{ $currencyName }}"
            name="{{ $currencyName }}"
            x-model="{{ $currencyModel }}"
            x-on:change="fetchQuote()"
            class="w-28 shrink-0 rounded-lg border border-slate-300 py-3 text-base sm:py-2.5 sm:text-sm pl-3 pr-8 shadow-sm
                text-slate-900 transition focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-100"
        >
            @foreach ($currencies as $code => $codeLabel)
                <option value="{{ $code }}">{{ $codeLabel }}</option>
            @endforeach
        </select>
        @endif
    </div>

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
    @error($currencyName)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
