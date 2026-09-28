<x-layouts.guest :title="__('Log in')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('Welcome back') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">{{ __('Log in with your email or phone number.') }}</p>

    @if (session('status'))
        <div class="mt-5">
            <x-alert type="success">{{ session('status') }}</x-alert>
        </div>
    @endif

    @error('login')
        <div class="mt-5">
            <x-alert type="error">{{ $message }}</x-alert>
        </div>
    @enderror

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf

        <x-input name="login" label="{{ __('Email or phone') }}" type="text" autofocus autocomplete="username" />

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="block text-sm font-medium text-slate-700">{{ __('Password') }}</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                        {{ __('Forgot password?') }}
                    </a>
                @endif
            </div>
            <x-input name="password" type="password" autocomplete="current-password" />
        </div>

        <x-checkbox name="remember" label="{{ __('Remember me') }}" />

        <x-button type="submit" class="w-full" size="lg">{{ __('Log in') }}</x-button>
    </form>
</x-layouts.guest>
