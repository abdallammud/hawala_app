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
            class="fixed inset-y-0 left-0 z-50 w-72 bg-brand-900 flex flex-col transition-transform duration-200 lg:translate-x-0"
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
        <div class="lg:pl-72 flex flex-col min-h-full">

            {{-- Topbar --}}
            <header class="sticky top-0 z-30 h-16 bg-white border-b border-slate-200 flex items-center gap-4 px-4 sm:px-6">
                <button type="button" x-on:click="drawerOpen = true" class="text-slate-500 hover:text-slate-700 lg:hidden">
                    <x-icon name="bars-3" class="w-6 h-6" />
                </button>

                <h1 class="text-lg font-semibold text-slate-900 truncate">{{ $title ?? __('Dashboard') }}</h1>

                <div class="ml-auto flex items-center gap-2">
                    <a href="{{ Route::has('notifications.index') ? route('notifications.index') : '#' }}" class="relative p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg">
                        <x-icon name="bell" class="w-5 h-5" />
                    </a>

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
</body>
</html>
