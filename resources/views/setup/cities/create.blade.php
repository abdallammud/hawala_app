<x-layouts.app :title="__('New City')">
    <div class="max-w-lg">
        <x-card :title="__('New city')">
            <form method="POST" action="{{ route('setup.cities.store') }}" class="space-y-5">
                @csrf

                <x-select name="country" label="{{ __('Country') }}" :options="array_combine($countries, $countries)" placeholder="{{ __('Select a country') }}" :value="$country" />
                <x-input name="name" label="{{ __('City') }}" autofocus />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('setup.cities.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Add city') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
