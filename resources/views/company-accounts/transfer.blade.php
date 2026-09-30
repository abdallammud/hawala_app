<x-layouts.app :title="__('Move Money')">
    <div class="max-w-lg">
        <x-card :title="__('Move money between company accounts')" :subtitle="__('Charges = sent − received (e.g. wire fees).')">
            <form method="POST" action="{{ route('company-accounts.transfer.store') }}" class="space-y-5">
                @csrf

                <x-select name="from_company_account_id" label="{{ __('From') }}" :options="$accounts" placeholder="{{ __('Select an account') }}" />
                <x-select name="to_company_account_id" label="{{ __('To') }}" :options="$accounts" placeholder="{{ __('Select an account') }}" />

                {{-- Stacked on a phone: two money fields side by side at 360px
                     leaves too little room to read what you typed. --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="sent" label="{{ __('Sent ($)') }}" type="number" min="0.01" step="0.01" prefix="$" />
                    <x-input name="received" label="{{ __('Received ($)') }}" type="number" min="0.01" step="0.01" prefix="$" hint="{{ __('Less than sent if there were charges.') }}" />
                </div>

                <x-input name="transferred_at" label="{{ __('Date') }}" type="date" :value="now()->toDateString()" />
                <x-input name="reference" label="{{ __('Reference') }}" hint="{{ __('Optional — wire reference, receipt number.') }}" />

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('company-accounts.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Move money') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
