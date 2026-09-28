<x-layouts.guest :title="__('Forgot password')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('Forgot your password?') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">{{ __("No problem. Enter your email and we'll send a reset link.") }}</p>

    @if (session('status'))
        <div class="mt-5">
            <x-alert type="success">{{ session('status') }}</x-alert>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf

        <x-input name="email" label="{{ __('Email') }}" type="email" autofocus />

        <x-button type="submit" class="w-full" size="lg">{{ __('Email password reset link') }}</x-button>

        <p class="text-center text-sm text-slate-500">
            <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">{{ __('Back to login') }}</a>
        </p>
    </form>
</x-layouts.guest>
