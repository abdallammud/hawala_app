<x-layouts.app :title="__('New Balance Request')">
    <div class="max-w-lg">
        <x-card :title="__('New request')">
            <form method="POST" action="{{ route('balance-requests.store') }}" x-data="{ kind: '' }" class="space-y-5">
                @csrf

                @if ($beneficiaries->isNotEmpty())
                    <x-select name="user_id" label="{{ __('For') }}" :options="$beneficiaries" placeholder="{{ __('Myself') }}" hint="{{ __('Leave blank to request for yourself.') }}" />
                @endif

                <x-select name="kind" label="{{ __('Kind') }}" :options="$kinds" placeholder="{{ __('Select a kind') }}" x-model="kind" />

                <x-input name="amount" label="{{ __('Amount ($)') }}" type="number" min="0.01" step="0.01" prefix="$" />

                <div x-show="['topup_paid', 'credit_repayment', 'payout_funding', 'payout_return'].includes(kind)" x-cloak>
                    <x-select name="company_account_id" label="{{ __('Company account') }}" :options="$companyAccounts" placeholder="{{ __('Select an account') }}" />
                </div>

                <x-textarea name="note" label="{{ __('Note') }}" hint="{{ __('Optional context for the approver.') }}" />

                <div class="flex justify-end gap-3">
                    <x-button :href="route('balance-requests.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Submit request') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
