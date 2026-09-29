<x-layouts.app :title="__('New Adjustment')">
    <div class="max-w-lg">
        <x-card :title="__('New manual adjustment')" :subtitle="__('Rare — corrects the ledger directly. Always needs a separate approver.')">
            <form method="POST" action="{{ route('adjustments.store') }}" class="space-y-5">
                @csrf

                <x-select name="debit_ledger_account_id" label="{{ __('Debit account') }}" :options="$accounts" placeholder="{{ __('Select an account') }}" hint="{{ __('This account will increase.') }}" />
                <x-select name="credit_ledger_account_id" label="{{ __('Credit account') }}" :options="$accounts" placeholder="{{ __('Select an account') }}" hint="{{ __('This account will decrease.') }}" />
                <x-input name="amount" label="{{ __('Amount ($)') }}" type="number" min="0.01" step="0.01" prefix="$" />
                <x-textarea name="reason" label="{{ __('Reason') }}" hint="{{ __('Required — shown to the approver and kept on the record.') }}" />

                <div class="flex justify-end gap-3">
                    <x-button :href="route('adjustments.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Submit for approval') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
