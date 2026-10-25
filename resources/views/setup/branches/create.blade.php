<x-layouts.app :title="__('New Branch')">
    <div class="max-w-lg">
        <x-card :title="__('New branch')">
            <form method="POST" action="{{ route('setup.branches.store') }}" class="space-y-5">
                @csrf

                <x-input name="name" label="{{ __('Name') }}" autofocus />
                <x-input name="city" label="{{ __('City (optional)') }}" />
                <x-checkbox name="is_payout_point" :checked="true" label="{{ __('Pays out transfers (offered as a destination)') }}" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('setup.branches.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Create branch') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
