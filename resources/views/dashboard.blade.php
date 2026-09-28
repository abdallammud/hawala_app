<x-layouts.app :title="__('Dashboard')">

    <x-alert type="info" title="{{ __('UI shell in progress') }}">
        {{ __('This dashboard shows the intended layout. Live balances, transfers and profit figures arrive as each module is built (BUILD.md phases 1–6).') }}
    </x-alert>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="{{ __('Send balance') }}" value="—" icon="wallet" tone="brand" />
        <x-stat label="{{ __('Waiting to be paid') }}" value="—" icon="inbox-stack" tone="gold" />
        <x-stat label="{{ __("Today's volume") }}" value="—" icon="chart-bar" tone="green" />
        <x-stat label="{{ __('Users') }}" :value="$userCount" icon="users" tone="slate" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <x-card :title="__('Recent transfers')" class="lg:col-span-2" :padding="false">
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers yet') }}" description="{{ __('Transfers will appear here once the transfer module is built (Phase 5).') }}" />
        </x-card>

        <x-card :title="__('Pending requests')" :padding="false">
            <x-empty-state icon="banknotes" title="{{ __('Nothing pending') }}" description="{{ __('Balance requests will appear here once built (Phase 3).') }}" />
        </x-card>
    </div>

</x-layouts.app>
