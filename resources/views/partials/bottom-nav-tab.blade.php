{{-- One tab of the phone bottom bar; see partials/bottom-nav. --}}
<a
    href="{{ $href }}"
    @if ($item['active']) aria-current="page" @endif
    class="relative flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[11px] font-medium active:bg-slate-50 {{ $item['active'] ? 'text-brand-700' : 'text-slate-500' }}"
>
    <span class="relative">
        <x-icon :name="$item['icon']" class="h-6 w-6 {{ $item['active'] ? 'text-brand-600' : '' }}" />
        @if ($item['count'] > 0)
            <span class="absolute -right-2.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white">
                {{ $item['count'] > 99 ? '99+' : $item['count'] }}
            </span>
        @endif
    </span>
    <span class="truncate">{{ __($item['tab']['label']) }}</span>
    @if ($item['active'])
        <span class="absolute inset-x-4 top-0 h-0.5 rounded-full bg-brand-600"></span>
    @endif
</a>
