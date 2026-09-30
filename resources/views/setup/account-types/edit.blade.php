<x-layouts.app :title="$accountType->name">
    <div class="max-w-lg">
        <x-card :title="$accountType->name">
            <form method="POST" action="{{ route('setup.account-types.update', $accountType) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="name" label="{{ __('Name') }}" :value="$accountType->name" />
                <x-input name="sort" label="{{ __('Sort order') }}" type="number" min="0" :value="$accountType->sort" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('setup.account-types.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
