<x-layouts.app :title="__('New Account Type')">
    <div class="max-w-lg">
        <x-card :title="__('New account type')">
            <form method="POST" action="{{ route('setup.account-types.store') }}" class="space-y-5">
                @csrf

                <x-input name="name" label="{{ __('Name') }}" hint="{{ __('e.g. EVC Plus, eDahab, Cash.') }}" autofocus />
                <x-input name="sort" label="{{ __('Sort order') }}" type="number" min="0" hint="{{ __('Lower numbers show first.') }}" />

                <div class="flex justify-end gap-3">
                    <x-button :href="route('setup.account-types.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Create account type') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
