<x-layouts.app :title="$branch->name">
    <div class="max-w-lg">
        <x-card :title="$branch->name">
            <form method="POST" action="{{ route('setup.branches.update', $branch) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="name" label="{{ __('Name') }}" :value="$branch->name" />
                <x-input name="city" label="{{ __('City') }}" :value="$branch->city" />
                <x-checkbox name="is_payout_point" :checked="$branch->is_payout_point" label="{{ __('Pays out transfers (offered as a destination)') }}" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('setup.branches.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
