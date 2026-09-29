<x-layouts.app :title="__('New Company Account')">
    <div class="max-w-lg">
        <x-card :title="__('New company account')">
            <form method="POST" action="{{ route('company-accounts.store') }}" class="space-y-5">
                @csrf

                <x-input name="name" label="{{ __('Name') }}" hint="{{ __('e.g. Europe office, Mogadishu bank, Company EVC.') }}" autofocus />
                <x-select name="location" label="{{ __('Location') }}" :options="['europe' => __('Europe'), 'somalia' => __('Somalia'), 'other' => __('Other')]" placeholder="{{ __('Select a location') }}" />

                <div class="flex justify-end gap-3">
                    <x-button :href="route('company-accounts.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Create account') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
