<x-layouts.guest :title="__('Reset password')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('Set a new password') }}</h1>
    <p class="mt-1.5 text-sm text-slate-500">{{ __('Choose a strong password for your account.') }}</p>

    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-input name="email" label="{{ __('Email') }}" type="email" :value="old('email', $request->email)" autofocus />

        <x-input name="password" label="{{ __('New password') }}" type="password" autocomplete="new-password" />

        <x-input name="password_confirmation" label="{{ __('Confirm password') }}" type="password" autocomplete="new-password" />

        <x-button type="submit" class="w-full" size="lg">{{ __('Reset password') }}</x-button>

        <p class="text-center text-sm text-slate-500">
            <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">{{ __('Back to login') }}</a>
        </p>
    </form>
</x-layouts.guest>
