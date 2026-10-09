<x-layouts.app :title="__('Rename expense type')">
    @include('partials.expenses-tabs', ['active' => 'types'])

    <div class="max-w-lg">
        <x-card :title="$type->name">
            <form method="POST" action="{{ route('expense-types.update', $type) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="name" label="{{ __('Name') }}" :value="$type->name" autofocus />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('expense-types.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
