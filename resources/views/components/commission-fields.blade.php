@props([
    'typeName', 'valueName', 'typeLabel', 'valueLabel',
    'type' => null, 'value' => null,
    'allowDefault' => true,
    'percentHint' => null,
])

@php
$currentType = old($typeName, $type);
$display = \App\Support\CommissionInput::toDisplay($type, $value);
@endphp

{{-- The stored value is basis points / cents; people type a plain % or dollar amount. --}}
<div x-data="{ ctype: @js($currentType ?? '') }" class="space-y-5">
    <x-select :name="$typeName" :label="$typeLabel" :options="\App\Enums\CommissionType::options()" :value="$type"
        :placeholder="$allowDefault ? __('Use default') : null" x-model="ctype" />

    <div>
        <x-input :name="$valueName" :label="$valueLabel" type="number" min="0" step="0.01" :value="$display"
            x-bind:disabled="!['percent', 'flat'].includes(ctype)"
            x-bind:max="ctype === 'percent' ? 100 : null"
            x-bind:placeholder="ctype === 'percent' ? '{{ __('e.g. 2.5') }}' : (ctype === 'flat' ? '{{ __('e.g. 0.20') }}' : '')" />
        <p x-show="ctype === 'percent'" x-cloak class="mt-1.5 text-xs text-slate-500">{{ $percentHint ?? __('Percent of the fee. Type 2.5 for 2.5%.') }}</p>
        <p x-show="ctype === 'flat'" x-cloak class="mt-1.5 text-xs text-slate-500">{{ __('Dollars per transfer. Type 0.20 for 20 cents.') }}</p>
        <p x-show="ctype === 'none'" x-cloak class="mt-1.5 text-xs text-slate-500">{{ __('No commission is paid.') }}</p>
        @if ($allowDefault)
            <p x-show="ctype === ''" x-cloak class="mt-1.5 text-xs text-slate-500">{{ __('The company default from Settings is used.') }}</p>
        @endif
    </div>
</div>
