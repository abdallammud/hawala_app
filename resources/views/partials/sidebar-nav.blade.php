@php
    $items = \App\Support\Menu::items(auth()->user());
@endphp

<nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
    @foreach ($items as $item)
        <a
            href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
            @if ($item['active']) aria-current="page" @endif
            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition
                {{ $item['active']
                    ? 'bg-brand-800 text-white'
                    : 'text-brand-100/80 hover:bg-brand-800/60 hover:text-white' }}"
        >
            <x-icon :name="$item['icon']" class="w-5 h-5 shrink-0 {{ $item['active'] ? 'text-gold-400' : 'text-brand-300 group-hover:text-gold-400' }}" />
            <span class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
