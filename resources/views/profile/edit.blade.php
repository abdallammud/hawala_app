<x-layouts.app :title="__('Profile')">
    <div class="max-w-2xl space-y-6">

        <x-card :title="__('Profile information')" :subtitle="__('Update your name, email and phone.')">
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-5">
                @csrf
                @method('PATCH')

                <x-input name="name" label="{{ __('Name') }}" :value="$user->name" />
                <x-input name="email" label="{{ __('Email') }}" type="email" :value="$user->email" />
                <x-input name="phone" label="{{ __('Phone') }}" :value="$user->phone" hint="{{ __('Used to log in as an alternative to email.') }}" />

                <div class="flex justify-end">
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
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
