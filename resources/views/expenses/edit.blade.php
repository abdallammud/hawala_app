<x-layouts.app :title="__('Edit expense')">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    <div class="max-w-2xl">
        <x-card :title="__('Edit expense')" :subtitle="__('The amount and the account it was paid from cannot change. To correct them, void this expense and add a new one.')">
            <form method="POST" action="{{ route('expenses.update', $expense) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="expense_type_id" label="{{ __('Expense type') }}" :options="$types" :value="$expense->expense_type_id" />
                    <x-input name="expense_date" label="{{ __('Date') }}" type="date" :value="$expense->expense_date->toDateString()" max="{{ today()->toDateString() }}" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="payee" label="{{ __('Paid to') }}" :value="$expense->payee" />
                    <x-input name="reference" label="{{ __('Reference') }}" :value="$expense->reference" />
                </div>

                <x-textarea name="description" label="{{ __('Note') }}" :value="$expense->description" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('expenses.show', $expense)" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
