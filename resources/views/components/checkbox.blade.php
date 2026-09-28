@props(['name', 'label' => null, 'checked' => false])

<label class="flex items-center gap-2.5 cursor-pointer select-none">
    <input
        type="checkbox"
        id="{{ $name }}"
        name="{{ $name }}"
        value="1"
        {{ $attributes->merge(['class' => 'h-4.5 w-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 focus:ring-offset-0']) }}
        @checked(old($name, $checked))
    />
    @if ($label)
        <span class="text-sm text-slate-700">{{ $label }}</span>
    @endif
</label>
