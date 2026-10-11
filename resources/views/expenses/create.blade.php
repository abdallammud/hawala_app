<x-layouts.app :title="__('Add expense')">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    <div class="max-w-2xl">
        <x-card :title="__('Add expense')" :subtitle="__('Paid out of a company account. It stays pending until someone else approves it; the money only leaves the account then.')">
            <form method="POST" action="{{ route('expenses.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="expense_type_id" label="{{ __('Expense type') }}" :options="$types" placeholder="{{ __('Choose a type') }}" />
                    <x-input name="expense_date" label="{{ __('Date') }}" type="date" :value="today()->toDateString()" max="{{ today()->toDateString() }}" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div class="sm:col-span-2">
                        <x-input name="amount" label="{{ __('Amount') }}" type="number" step="0.01" min="0.01" inputmode="decimal" />
                    </div>
                    <x-select name="currency" label="{{ __('Currency') }}" :options="$currencies" :value="old('currency', 'USD')" />
                </div>

                <x-select name="company_account_id" label="{{ __('Paid from') }}" :options="$accounts" placeholder="{{ __('Choose an account') }}" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="payee" label="{{ __('Paid to') }}" hint="{{ __('Optional — e.g. the landlord or staff member') }}" />
                    <x-input name="reference" label="{{ __('Reference') }}" hint="{{ __('Optional — receipt or invoice number') }}" />
                </div>

                <x-textarea name="description" label="{{ __('Note') }}" />

                @include('expenses.partials.attachment-input')

                <p class="text-xs text-slate-500">
                    {{ __('Recorded under your branch') }}: <span class="font-medium text-slate-700">{{ $branch?->name ?? __('none') }}</span>
                </p>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('expenses.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Submit for approval') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
