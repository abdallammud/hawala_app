@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
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
<body class="h-full font-sans antialiased bg-brand-900">
    <div class="min-h-full flex flex-col lg:flex-row">

        {{-- Brand panel --}}
        <div class="hidden lg:flex lg:w-2/5 xl:w-1/3 bg-brand-900 flex-col justify-between p-10 relative overflow-hidden">
            <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 20% 20%, white 1px, transparent 1px); background-size: 24px 24px;"></div>

            <div class="relative">
                <img
                    src="{{ asset('images/logo-mark.png') }}"
                    alt="{{ config('app.name') }}"
                    class="mx-auto h-36 w-36 xl:h-44 xl:w-44 rounded-full bg-white p-2 shadow-xl ring-4 ring-white/10"
                >
            </div>

            <div class="relative text-center">
                <h2 class="text-2xl font-semibold text-white leading-snug">
                    {{ __('Send with trust.') }}<br>{{ __('Pay with confidence.') }}
                </h2>
                <p class="mt-3 text-brand-200 text-sm max-w-xs mx-auto">
                    {{ __('Internal remittance management for :name — balances, transfers and payouts in one place.', ['name' => \App\Support\Settings::get('general.company_name', config('app.name'))]) }}
                </p>
            </div>

            <p class="relative text-xs text-brand-400">
                &copy; {{ now()->year }} {{ \App\Support\Settings::get('general.company_name', config('app.name')) }}
            </p>
        </div>

        {{-- Form panel --}}
        <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 bg-slate-50">
            <div class="w-full max-w-sm">
                <div class="lg:hidden flex justify-center mb-8">
                    <img
                        src="{{ asset('images/logo-mark.png') }}"
                        alt="{{ config('app.name') }}"
                        class="h-32 w-32 sm:h-36 sm:w-36 rounded-full bg-white p-2 shadow-md ring-1 ring-slate-200"
                    >
                </div>

                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
