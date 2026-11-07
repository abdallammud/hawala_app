{{-- Phone bottom bar: up to four shortcuts picked for this user's role, a raised
     centre button for the main action, and "More" which opens the sidebar.
     Phones and small tablets only (`lg` and up has the sidebar). Lives inside
     the layout's x-data scope, so More just sets drawerOpen. --}}
@php
    $tabs = \App\Support\Menu::bottomTabs(auth()->user());

    $link = fn (array $item) => Route::has($item['route']) ? route($item['route']) : '#';
@endphp

<nav
    aria-label="{{ __('Main') }}"
    class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white lg:hidden print:hidden"
    style="padding-bottom: env(safe-area-inset-bottom)"
>
    <div class="mx-auto flex max-w-lg items-stretch justify-around">
        @foreach ($tabs['left'] as $item)
            @include('partials.bottom-nav-tab', ['item' => $item, 'href' => $link($item)])
        @endforeach

        @if ($tabs['primary'])
            <a
                href="{{ $link($tabs['primary']) }}"
                @if ($tabs['primary']['active']) aria-current="page" @endif
                class="flex min-w-0 flex-1 flex-col items-center justify-end gap-0.5 pb-1.5 text-[11px] font-medium {{ $tabs['primary']['active'] ? 'text-brand-700' : 'text-slate-500' }}"
            >
                <span class="-mt-5 flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg ring-4 ring-white transition active:bg-brand-700">
                    <x-icon :name="$tabs['primary']['icon']" class="h-7 w-7" />
                </span>
                <span class="truncate">{{ __($tabs['primary']['tab']['label']) }}</span>
            </a>
        @endif

        @foreach ($tabs['right'] as $item)
            @include('partials.bottom-nav-tab', ['item' => $item, 'href' => $link($item)])
        @endforeach

        <button
            type="button"
            x-on:click="drawerOpen = true"
            class="flex min-h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[11px] font-medium text-slate-500 active:bg-slate-50"
        >
            <x-icon name="bars-3" class="h-6 w-6" />
            <span class="truncate">{{ __('More') }}</span>
        </button>
    </div>
</nav>
