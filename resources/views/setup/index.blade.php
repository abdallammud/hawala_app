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

        <x-card :title="__('Currencies')" :subtitle="__('Exchange rates and manual overrides.')">
            <x-badge color="gold">{{ __('Coming in Phase 4') }}</x-badge>
        </x-card>

        <x-card :title="__('Fee Tiers')" :subtitle="__('Fee rules with a live preview.')">
            <x-badge color="gold">{{ __('Coming in Phase 4') }}</x-badge>
        </x-card>

        <x-card :title="__('Settings')" :subtitle="__('Fees, commission, currency, payout, credit, SMS, general.')">
            <x-badge color="gold">{{ __('Coming in Phase 3') }}</x-badge>
        </x-card>

        <x-card :title="__('SMS Templates')" :subtitle="__('Templates for each transfer event.')">
            <x-badge color="gold">{{ __('Coming in Phase 7') }}</x-badge>
        </x-card>

    </div>
</x-layouts.app>
