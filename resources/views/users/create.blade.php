<x-layouts.app :title="__('New User')">
    <div class="max-w-2xl">
        <x-card :title="__('New user')" :subtitle="__('Staff, sending agents and payout agents are all created here.')">
            <form method="POST" action="{{ route('users.store') }}" x-data="{ type: '{{ old('type', 'staff') }}' }" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="name" label="{{ __('Name') }}" autofocus />
                    <x-input name="email" label="{{ __('Email') }}" type="email" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="phone" label="{{ __('Phone') }}" hint="{{ __('Optional — can also be used to log in.') }}" />
                    <x-input name="password" label="{{ __('Initial password') }}" type="password" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="type" label="{{ __('User type') }}" :options="$types" x-model="type" />
                    <x-select name="role" label="{{ __('Role') }}" :options="$roles" placeholder="{{ __('Select a role') }}" />
                </div>

                <x-select name="branch_id" label="{{ __('Branch') }}" :options="$branches" placeholder="{{ __('Select a branch') }}" />

                <div class="border-t border-slate-100 pt-5">
                    <p class="text-sm font-medium text-slate-700 mb-1">{{ __('Commission overrides') }}</p>
                    <p class="text-xs text-slate-500 mb-4">{{ __('Leave blank to use the company default from Settings.') }}</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div x-show="type !== 'payout_agent'" x-cloak class="space-y-5">
                            <x-commission-fields type-name="send_commission_type" value-name="send_commission_value" :type-label="__('Send commission type')" :value-label="__('Send commission value')" />
                        </div>
                        <div x-show="['payout_agent', 'dual_agent'].includes(type)" x-cloak class="space-y-5">
                            <x-commission-fields type-name="payout_commission_type" value-name="payout_commission_value" :type-label="__('Payout commission type')" :value-label="__('Payout commission value')" :percent-hint="__('Percent of the fee left after the sending agent\'s share. Type 20 for 20%.')" />
                        </div>
                    </div>
                </div>

                <div x-show="['sending_agent', 'dual_agent'].includes(type)" x-cloak class="border-t border-slate-100 pt-5">
                    <x-input name="max_credit" label="{{ __('Max credit ($)') }}" type="number" min="0" step="0.01" hint="{{ __('Leave blank for the company default / no limit.') }}" prefix="$" />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <x-button :href="route('users.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Create user') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
