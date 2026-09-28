@php $menu = config('menu'); @endphp

<nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6">
    @foreach ($menu as $section)
        <div>
            @if ($section['group'])
                <p class="px-3 mb-1.5 text-xs font-semibold uppercase tracking-wider text-brand-300">
                    {{ $section['group'] }}
                </p>
            @endif

            <div class="space-y-0.5">
                @foreach ($section['items'] as $item)
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
            </div>
        </div>
    @endforeach
</nav>
