<x-layouts.app :title="__('Edit expense')">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div class="max-w-2xl">
        <x-card :title="__('Edit expense :number', ['number' => $expense->number])" :subtitle="__('Pending expenses can be changed freely. Once approved or rejected, an expense is locked.')">
            <form method="POST" action="{{ route('expenses.update', $expense) }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="expense_type_id" label="{{ __('Expense type') }}" :options="$types" :value="$expense->expense_type_id" />
                    <x-input name="expense_date" label="{{ __('Date') }}" type="date" :value="$expense->expense_date->toDateString()" max="{{ today()->toDateString() }}" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div class="sm:col-span-2">
                        <x-input name="amount" label="{{ __('Amount') }}" type="number" step="0.01" min="0.01" inputmode="decimal" :value="number_format($expense->original_amount_cents / 100, 2, '.', '')" />
                    </div>
                    <x-select name="currency" label="{{ __('Currency') }}" :options="$currencies" :value="old('currency', $expense->original_currency)" />
                </div>

                <x-select name="company_account_id" label="{{ __('Paid from') }}" :options="$accounts" :value="$expense->company_account_id" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="payee" label="{{ __('Paid to') }}" :value="$expense->payee" />
                    <x-input name="reference" label="{{ __('Reference') }}" :value="$expense->reference" />
                </div>

                <x-textarea name="description" label="{{ __('Note') }}" :value="$expense->description" />

                @include('expenses.partials.attachment-input', ['current' => $expense->attachment_name])

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('expenses.show', $expense)" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
