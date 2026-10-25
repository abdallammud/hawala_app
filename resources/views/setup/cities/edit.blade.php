<x-layouts.app :title="$city->name">
    <div class="max-w-lg">
        <x-card :title="$city->name">
            <form method="POST" action="{{ route('setup.cities.update', $city) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-select name="country" label="{{ __('Country') }}" :options="array_combine($countries, $countries)" :value="$city->country" />
                <x-input name="name" label="{{ __('City') }}" :value="$city->name" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('setup.cities.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
