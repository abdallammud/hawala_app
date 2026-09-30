@props(['name', 'label' => null, 'checked' => false])

{{-- The whole row is the tap target, and on a phone it is at least 44px tall:
     a bare 18px box is far too small to hit at a counter. --}}
<label class="flex items-center gap-3 cursor-pointer select-none min-h-11 sm:min-h-0">
    <input
        type="checkbox"
        id="{{ $name }}"
        name="{{ $name }}"
        value="1"
        {{ $attributes->merge(['class' => 'h-5 w-5 sm:h-4.5 sm:w-4.5 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500 focus:ring-offset-0']) }}
        @checked(old($name, $checked))
    />
    @if ($label)
        <span class="text-sm text-slate-700">{{ $label }}</span>
    @endif
</label>
