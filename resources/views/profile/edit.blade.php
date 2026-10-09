<x-layouts.app :title="__('Profile')">
    <div class="max-w-2xl space-y-6">

        <x-card :title="__('Profile information')" :subtitle="__('Update your name, email and phone.')">
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-5">
                @csrf
                @method('PATCH')

                <x-input name="name" label="{{ __('Name') }}" :value="$user->name" />
                <x-input name="email" label="{{ __('Email') }}" type="email" :value="$user->email" />
                <x-input name="phone" label="{{ __('Phone') }}" :value="$user->phone" hint="{{ __('Used to log in as an alternative to email.') }}" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('dashboard')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>

        @php
            $twoFactor = app(\App\Services\Auth\TwoFactorService::class);
            $twoFactorRequired = $twoFactor->required();
        @endphp
        <x-card :title="__('Two-factor authentication')" :subtitle="__('A code from your authenticator app, asked for every time you log in.')">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-slate-700">
                    @if ($user->hasTwoFactorEnabled())
                        {{ __('On since :date.', ['date' => $user->two_factor_confirmed_at->format('Y-m-d')]) }}
                        {{ trans_choice(':count recovery code left|:count recovery codes left', $twoFactor->recoveryCodesLeft($user), ['count' => $twoFactor->recoveryCodesLeft($user)]) }}
                    @else
                        {{ __('Not set up yet.') }}
                    @endif
                </p>
                <x-badge :color="$user->hasTwoFactorEnabled() ? 'green' : 'amber'">{{ $user->hasTwoFactorEnabled() ? __('On') : __('Off') }}</x-badge>
            </div>

            @if ($user->hasTwoFactorEnabled())
                <form method="POST" action="{{ route('two-factor.regenerate') }}" class="mt-5 pt-5 border-t border-slate-100 space-y-4">
                    @csrf
                    <p class="text-sm font-medium text-slate-900">{{ __('Make new recovery codes') }}</p>
                    <p class="text-xs text-slate-500">{{ __('The old ones stop working.') }}</p>
                    <x-input name="current_password" label="{{ __('Current password') }}" type="password" autocomplete="current-password" />
                    <div class="flex justify-end"><x-button type="submit" variant="secondary">{{ __('Make new codes') }}</x-button></div>
                </form>

                @unless ($twoFactorRequired)
                    <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-5 pt-5 border-t border-slate-100 space-y-4">
                        @csrf
                        @method('DELETE')
                        <p class="text-sm font-medium text-slate-900">{{ __('Turn off two-factor') }}</p>
                        <x-input name="current_password" label="{{ __('Current password') }}" type="password" autocomplete="current-password" />
                        <div class="flex justify-end"><x-button type="submit" variant="danger">{{ __('Turn off') }}</x-button></div>
                    </form>
                @endunless
            @else
                <div class="mt-5"><x-button :href="route('two-factor.setup')">{{ __('Set up now') }}</x-button></div>
            @endif
        </x-card>

        <x-card :title="__('Change password')">
            <form method="POST" action="{{ route('profile.password') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="current_password" label="{{ __('Current password') }}" type="password" />
                <x-input name="password" label="{{ __('New password') }}" type="password" />
                <x-input name="password_confirmation" label="{{ __('Confirm new password') }}" type="password" />

                <div class="flex justify-end">
                    <x-button type="submit" variant="secondary">{{ __('Update password') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
