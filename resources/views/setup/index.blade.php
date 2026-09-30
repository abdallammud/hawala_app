<x-layouts.app :title="__('Setup')">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        @can('setup.branches')
            <x-card :title="__('Branches')" :subtitle="__('Payout branches like Mogadishu, Hargeisa.')">
                <x-button :href="route('setup.branches.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.account_types')
            <x-card :title="__('Account Types')" :subtitle="__('EVC Plus, eDahab, Zaad, bank, cash…')">
                <x-button :href="route('setup.account-types.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.roles')
            <x-card :title="__('Roles')" :subtitle="__('Roles and their permissions.')">
                <x-button :href="route('roles.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.currencies')
            <x-card :title="__('Currencies')" :subtitle="__('Exchange rates and manual overrides.')">
                <x-button :href="route('setup.currencies.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.fees')
            <x-card :title="__('Fee Tiers')" :subtitle="__('Fee rules with a live preview.')">
                <x-button :href="route('setup.fee-tiers.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.settings')
            <x-card :title="__('Settings')" :subtitle="__('Fees, commission, currency, payout, credit, general.')">
                <x-button :href="route('setup.settings.edit')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

        @can('setup.sms')
            <x-card :title="__('SMS Templates')" :subtitle="__('Templates for each transfer event.')">
                <x-button :href="route('setup.sms-templates.index')" variant="secondary" size="sm">{{ __('Manage') }}</x-button>
            </x-card>
        @endcan

    </div>
</x-layouts.app>
