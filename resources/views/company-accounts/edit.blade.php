<x-layouts.app :title="$account->name">
    <div class="max-w-lg">
        <x-card :title="$account->name">
            <form method="POST" action="{{ route('company-accounts.update', $account) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="name" label="{{ __('Name') }}" :value="$account->name" />
                <x-select name="location" label="{{ __('Location') }}" :options="['europe' => __('Europe'), 'somalia' => __('Somalia'), 'other' => __('Other')]" :value="$account->location" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('company-accounts.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
