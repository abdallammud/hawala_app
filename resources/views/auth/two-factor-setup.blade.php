<x-layouts.guest :title="__('Set up two-factor')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('Protect your account') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">
        @if ($required)
            {{ __('Before you continue, link an authenticator app. You will need it every time you log in.') }}
        @else
            {{ __('Link an authenticator app. You will need it every time you log in.') }}
        @endif
    </p>

    <ol class="mt-6 space-y-6 text-sm text-slate-700">
        <li>
            <p class="font-medium text-slate-900">1. {{ __('Install an app') }}</p>
            <p class="mt-1 text-slate-500">{{ __('Google Authenticator, Microsoft Authenticator or Authy — free from your phone’s app store.') }}</p>
        </li>

        <li>
            <p class="font-medium text-slate-900">2. {{ __('Scan this code in the app') }}</p>
            <div class="mt-3 inline-block rounded-xl border border-slate-200 bg-white p-3 [&>svg]:block [&>svg]:h-52 [&>svg]:w-52">
                {!! $qr !!}
            </div>
            <details class="mt-3">
                <summary class="cursor-pointer text-slate-500 hover:text-slate-700">{{ __('Can’t scan? Type the key instead') }}</summary>
                <p class="mt-2 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm tracking-wider text-slate-900 select-all">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
            </details>
        </li>

        <li>
            <p class="font-medium text-slate-900">3. {{ __('Type the 6-digit code it shows') }}</p>
            <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-3 space-y-4">
                @csrf
                <x-input name="code" label="{{ __('6-digit code') }}" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus />
                <x-button type="submit" class="w-full" size="lg">{{ __('Turn on and continue') }}</x-button>
            </form>
        </li>
    </ol>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm text-slate-500 hover:text-slate-700">{{ __('Log out') }}</button>
    </form>
</x-layouts.guest>
