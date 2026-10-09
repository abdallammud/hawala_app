<x-layouts.app :title="__('Edit customer')">
    <div class="max-w-lg">
        <x-card :title="$customer->name">
            <form method="POST" action="{{ route('customers.update', $customer) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <datalist id="country-list">
                    @foreach ($countries as $country)
                        <option value="{{ $country }}"></option>
                    @endforeach
                </datalist>

                <x-input name="name" label="{{ __('Name') }}" :value="$customer->name" />
                <x-input name="phone" label="{{ __('Phone') }}" :value="$customer->phoneLabel()" />
                @error('phone_digits')
                    <p class="-mt-3 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <x-input name="country" label="{{ __('Country') }}" list="country-list" :value="$customer->country" />
                <x-input name="city" label="{{ __('City') }}" :value="$customer->city" />
                <x-input name="province" label="{{ __('Province / region') }}" :value="$customer->province" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('customers.show', $customer)" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
