@php
    $items = collect(config('menu'))
        ->filter(fn ($item) => ! $item['permission'] || auth()->user()->can($item['permission']));
@endphp

<nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
    @foreach ($items as $item)
        @php
            $isActive = request()->routeIs($item['route'].'*');
        @endphp
        <a
            href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition
                {{ $isActive
                    ? 'bg-brand-800 text-white'
                    : 'text-brand-100/80 hover:bg-brand-800/60 hover:text-white' }}"
        >
            <x-icon :name="$item['icon']" class="w-5 h-5 shrink-0 {{ $isActive ? 'text-gold-400' : 'text-brand-300 group-hover:text-gold-400' }}" />
            <span class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
