@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon-32x32.png') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased text-slate-900">
    <div x-data="{ drawerOpen: false }" class="h-full">

        {{-- Mobile drawer backdrop --}}
        <div
            x-show="drawerOpen"
            x-cloak
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:click="drawerOpen = false"
            class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
        ></div>

        {{-- Sidebar --}}
        <aside
            x-cloak
            :class="drawerOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            class="fixed inset-y-0 left-0 z-50 w-72 bg-brand-900 flex flex-col transition-transform duration-200 lg:translate-x-0 print:hidden"
        >
            <div class="h-16 flex items-center gap-3 px-5 border-b border-brand-800 shrink-0">
                <img src="{{ asset('images/logo-mark.png') }}" alt="{{ config('app.name') }}" class="h-9 w-9 rounded-full bg-white p-0.5 shrink-0">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-white truncate">{{ \App\Support\Settings::get('general.company_name', config('app.name')) }}</p>
                    <p class="text-xs text-brand-300 truncate">{{ __('Remittance System') }}</p>
                </div>
                <button type="button" x-on:click="drawerOpen = false" class="ml-auto text-brand-300 hover:text-white lg:hidden">
                    <x-icon name="x-mark" class="w-5 h-5" />
                </button>
            </div>

            @include('partials.sidebar-nav')

            <div class="border-t border-brand-800 p-3 shrink-0">
                <div class="flex items-center gap-3 rounded-lg px-3 py-2.5">
                    <div class="h-9 w-9 rounded-full bg-brand-700 text-white flex items-center justify-center text-sm font-semibold shrink-0">
                        {{ auth()->check() ? strtoupper(substr(auth()->user()->name, 0, 1)) : '?' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name ?? __('Guest') }}</p>
                        <p class="text-xs text-brand-300 truncate">{{ auth()->user()->email ?? '' }}</p>
                    </div>
                </div>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="lg:pl-72 print:pl-0 flex flex-col min-h-full">

            {{-- Send balance is the number a sending agent needs on every screen,
                 so it sits in the topbar rather than only on the dashboard: a
                 chip from `sm` up, and its own full-width strip on a phone where
                 the topbar has no room. Null means this user isn't someone who
                 sends, so nobody else gets a meaningless $0.00. --}}
            @php
                $sendBalance = auth()->user()?->sendBalanceCents();
                $sendTone = $sendBalance > 0
                    ? ['border' => 'border-brand-200', 'bg' => 'bg-brand-50', 'hover' => 'hover:bg-brand-100', 'icon' => 'bg-brand-600', 'label' => 'text-brand-700', 'value' => 'text-brand-900']
                    : ['border' => 'border-red-300', 'bg' => 'bg-red-50', 'hover' => 'hover:bg-red-100', 'icon' => 'bg-red-600', 'label' => 'text-red-700', 'value' => 'text-red-900'];
                $sendHref = Route::has('balance-requests.index') ? route('balance-requests.index') : '#';
            @endphp

            {{-- Topbar --}}
            <div class="sticky top-0 z-30 print:hidden">
            <header class="h-16 bg-white border-b border-slate-200 flex items-center gap-3 sm:gap-4 px-4 sm:px-6">
                <button type="button" x-on:click="drawerOpen = true" class="text-slate-500 hover:text-slate-700 lg:hidden shrink-0">
                    <x-icon name="bars-3" class="w-6 h-6" />
                </button>

                <h1 class="text-lg font-semibold text-slate-900 truncate">{{ $title ?? __('Dashboard') }}</h1>

                @if ($sendBalance !== null)
                    <a
                        href="{{ $sendHref }}"
                        class="hidden sm:flex items-center gap-2.5 rounded-lg border px-3 py-1.5 shrink-0 transition {{ $sendTone['bg'] }} {{ $sendTone['border'] }} {{ $sendTone['hover'] }}"
                        title="{{ __('Send balance') }}"
                    >
                        <span class="shrink-0 rounded-md p-1.5 shadow-sm text-white {{ $sendTone['icon'] }}">
                            <x-icon name="wallet" class="w-4 h-4" />
                        </span>
                        <span>
                            <span class="block text-[10px] font-semibold uppercase tracking-wide leading-none {{ $sendTone['label'] }}">
                                {{ __('Send balance') }}
                            </span>
                            <span class="block font-mono tabular-nums text-sm font-bold leading-tight mt-0.5 {{ $sendTone['value'] }}">
                                {{ \App\Support\Money::format($sendBalance) }}
                            </span>
                        </span>
                    </a>
                @endif

                <div class="ml-auto flex items-center gap-2 shrink-0">
                    @php $unread = auth()->check() ? auth()->user()->unreadNotifications()->count() : 0; @endphp
                    <div
                        class="flex items-center gap-1"
                        x-data="notificationBell({
                            pollUrl: '{{ route('notifications.poll') }}',
                            indexUrl: '{{ route('notifications.index') }}',
                            unread: {{ $unread }},
                            title: @js(config('app.name')),
                        })"
                    >
                        <button
                            type="button"
                            x-show="permission === 'default'"
                            x-cloak
                            x-on:click="enable()"
                            class="hidden sm:block text-xs font-medium text-brand-700 hover:bg-brand-50 rounded-lg px-2 py-1.5"
                            title="{{ __('Get a sound and pop-up when something needs your attention') }}"
                        >{{ __('Enable alerts') }}</button>

                        <a href="{{ route('notifications.index') }}" class="relative p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg">
                            <x-icon name="bell" class="w-5 h-5" />
                            <span
                                x-show="unread > 0"
                                x-cloak
                                x-text="unread > 9 ? '9+' : unread"
                                class="absolute top-1 right-1 h-4 min-w-4 px-1 rounded-full bg-gold-500 text-white text-[10px] font-semibold flex items-center justify-center"
                            >{{ $unread > 9 ? '9+' : $unread }}</span>
                        </a>
                    </div>

                    <div x-data="{ open: false }" class="relative">
                        <button type="button" x-on:click="open = !open" x-on:click.outside="open = false" class="flex items-center gap-2 p-1.5 pr-2.5 rounded-lg hover:bg-slate-100">
                            <div class="h-8 w-8 rounded-full bg-brand-600 text-white flex items-center justify-center text-xs font-semibold">
                                {{ auth()->check() ? strtoupper(substr(auth()->user()->name, 0, 1)) : '?' }}
                            </div>
                            <x-icon name="chevron-down" class="w-4 h-4 text-slate-400 hidden sm:block" />
                        </button>

                        <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="ease-out duration-150"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute right-0 mt-2 w-56 origin-top-right rounded-lg bg-white shadow-lg ring-1 ring-slate-200 py-1.5"
                        >
                            <div class="px-3.5 py-2 border-b border-slate-100">
                                <p class="text-sm font-medium text-slate-900 truncate">{{ auth()->user()->name ?? '' }}</p>
                                <p class="text-xs text-slate-500 truncate">{{ auth()->user()->email ?? '' }}</p>
                            </div>
                            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                <x-icon name="user-circle" class="w-4 h-4 text-slate-400" />
                                {{ __('Profile') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    <x-icon name="arrow-right-on-rectangle" class="w-4 h-4 text-slate-400" />
                                    {{ __('Log out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Phone: the send balance gets its own row, so it is never
                 squeezed out of the topbar by the page title. --}}
            @if ($sendBalance !== null)
                <a
                    href="{{ $sendHref }}"
                    class="sm:hidden flex items-center gap-3 border-b px-4 py-2.5 {{ $sendTone['bg'] }} {{ $sendTone['border'] }}"
                >
                    <span class="shrink-0 rounded-md p-1.5 shadow-sm text-white {{ $sendTone['icon'] }}">
                        <x-icon name="wallet" class="w-4 h-4" />
                    </span>
                    <span class="text-[11px] font-semibold uppercase tracking-wide {{ $sendTone['label'] }}">
                        {{ __('Send balance') }}
                    </span>
                    <span class="ml-auto font-mono tabular-nums text-base font-bold {{ $sendTone['value'] }}">
                        {{ \App\Support\Money::format($sendBalance) }}
                    </span>
                </a>
            @endif
            </div>

            @include('partials.agent-banners')

            {{-- Page content --}}
            <main class="flex-1 px-4 sm:px-6 py-6">
                <div class="max-w-7xl mx-auto space-y-6">
                    @if (session('status'))
                        <x-alert type="success" dismissible>{{ session('status') }}</x-alert>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @php $idleMinutes = (int) \App\Support\Settings::get('security.idle_timeout_minutes', 15); @endphp
    @if ($idleMinutes > 0)
        {{-- Sign out after real inactivity. Typing or tapping counts as
             activity and quietly tells the server, so a long form is safe;
             a minute before the end a banner offers to stay signed in. --}}
        <div
            x-data="{
                limit: {{ $idleMinutes * 60 }},
                left: {{ $idleMinutes * 60 }},
                lastActive: Date.now(),
                lastPing: Date.now(),
                csrf: document.querySelector('meta[name=csrf-token]').content,
                init() {
                    ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(e =>
                        window.addEventListener(e, () => this.activity(), { passive: true }));
                    setInterval(() => this.tick(), 1000);
                },
                activity() {
                    this.lastActive = Date.now();
                    // Tell the server now and then, not on every mouse move.
                    if (Date.now() - this.lastPing > Math.min(this.limit / 3, 120) * 1000) { this.ping(); }
                },
                stay() { this.lastActive = Date.now(); this.ping(); },
                ping() {
                    this.lastPing = Date.now();
                    fetch('{{ route('session.keepalive') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    }).then(r => { if (r.status === 401 || r.status === 419) { this.leave(); } }).catch(() => {});
                },
                tick() {
                    this.left = Math.max(0, Math.ceil(this.limit - (Date.now() - this.lastActive) / 1000));
                    if (this.left === 0) { this.leave(); }
                },
                leave() {
                    fetch('{{ route('logout') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf } })
                        .finally(() => { window.location = '{{ route('login', ['idle' => 1]) }}'; });
                },
            }"
            x-show="left <= 60"
            x-cloak
            class="fixed inset-x-0 bottom-0 z-50 p-4 sm:p-6 pointer-events-none"
            role="alert"
        >
            <div class="pointer-events-auto mx-auto max-w-md rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-lg flex items-center justify-between gap-4">
                <p class="text-sm font-medium text-amber-900">
                    {{ __('You will be signed out in') }} <span x-text="left"></span> {{ __('seconds because you have been inactive.') }}
                </p>
                <button type="button" x-on:click="stay()" class="shrink-0 rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                    {{ __('Stay signed in') }}
                </button>
            </div>
        </div>
    @endif
</body>
</html>
