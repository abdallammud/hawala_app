<x-layouts.guest :title="__('Confirm it is you')">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('Enter your code') }}</h1>

        <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="mt-6 space-y-5">
            @csrf

            <div x-show="!recovery">
                <p class="text-sm text-slate-500 mb-4">{{ __('Open your authenticator app and type the 6-digit code for :app.', ['app' => \App\Support\Settings::get('general.company_name', config('app.name'))]) }}</p>
                <x-input name="code" label="{{ __('6-digit code') }}" inputmode="numeric" autocomplete="one-time-code" maxlength="7" x-bind:disabled="recovery" autofocus />
            </div>

            <div x-show="recovery" x-cloak>
                <p class="text-sm text-slate-500 mb-4">{{ __('Lost your phone? Type one of the recovery codes you saved. Each one works only once.') }}</p>
                <x-input name="recovery_code" label="{{ __('Recovery code') }}" autocomplete="off" maxlength="16" x-bind:disabled="!recovery" />
            </div>

            <x-button type="submit" class="w-full" size="lg">{{ __('Log in') }}</x-button>

            <div class="flex items-center justify-between text-sm">
                <button type="button" class="font-medium text-brand-600 hover:text-brand-700" x-on:click="recovery = !recovery"
                    x-text="recovery ? '{{ __('Use my authenticator app') }}' : '{{ __('Use a recovery code') }}'"></button>
                <a href="{{ route('login') }}" class="text-slate-500 hover:text-slate-700">{{ __('Start over') }}</a>
            </div>
        </form>
    </div>
</x-layouts.guest>
