<x-layouts.app :title="$user->name">
    <div class="max-w-2xl space-y-6">

        <x-card>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="h-11 w-11 rounded-full bg-brand-600 text-white flex items-center justify-center font-semibold">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                    <div>
                        <p class="font-semibold text-slate-900">{{ $user->name }}</p>
                        <p class="text-sm text-slate-500">{{ $user->email }}</p>
                    </div>
                </div>

                @can('deactivate', $user)
                    @if ($user->is_active)
                        <form method="POST" action="{{ route('users.deactivate', $user) }}">
                            @csrf
                            <x-button type="submit" variant="danger" size="sm">{{ __('Deactivate') }}</x-button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('users.activate', $user) }}">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm">{{ __('Reactivate') }}</x-button>
                        </form>
                    @endif
                @endcan
            </div>

            @error('status')
                <div class="mt-4">
                    <x-alert type="error">{{ $message }}</x-alert>
                </div>
            @enderror
        </x-card>

        <x-card :title="__('Details')">
            <form method="POST" action="{{ route('users.update', $user) }}" x-data="{ type: '{{ old('type', $user->type->value) }}' }" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="name" label="{{ __('Name') }}" :value="$user->name" />
                    <x-input name="email" label="{{ __('Email') }}" type="email" :value="$user->email" />
                </div>

                <x-input name="phone" label="{{ __('Phone') }}" :value="$user->phone" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="type" label="{{ __('User type') }}" :options="$types" :value="$user->type->value" x-model="type" />
                    <x-select name="role" label="{{ __('Role') }}" :options="$roles" :value="$user->roles->first()?->name" placeholder="{{ __('Select a role') }}" />
                </div>

                <div x-show="type === 'payout_agent'" x-cloak>
                    <x-select name="branch_id" label="{{ __('Branch') }}" :options="$branches" :value="$user->branch_id" placeholder="{{ __('Select a branch') }}" />
                </div>

                <div class="border-t border-slate-100 pt-5">
                    <p class="text-sm font-medium text-slate-700 mb-1">{{ __('Commission overrides') }}</p>
                    <p class="text-xs text-slate-500 mb-4">{{ __('Leave blank to use the company default from Settings.') }}</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div x-show="type !== 'payout_agent'" x-cloak class="space-y-5">
                            <x-select name="send_commission_type" label="{{ __('Send commission type') }}" :options="\App\Enums\CommissionType::options()" :value="$user->send_commission_type?->value" placeholder="{{ __('Use default') }}" />
                            <x-input name="send_commission_value" label="{{ __('Send commission value') }}" type="number" min="0" :value="$user->send_commission_value" hint="{{ __('Basis points if percent (500 = 5%), cents if flat.') }}" />
                        </div>
                        <div x-show="type === 'payout_agent'" x-cloak class="space-y-5">
                            <x-select name="payout_commission_type" label="{{ __('Payout commission type') }}" :options="\App\Enums\CommissionType::options()" :value="$user->payout_commission_type?->value" placeholder="{{ __('Use default') }}" />
                            <x-input name="payout_commission_value" label="{{ __('Payout commission value') }}" type="number" min="0" :value="$user->payout_commission_value" hint="{{ __('Basis points if percent, cents if flat.') }}" />
                        </div>
                    </div>
                </div>

                <div x-show="type === 'sending_agent'" x-cloak class="border-t border-slate-100 pt-5">
                    <x-input name="max_credit" label="{{ __('Max credit ($)') }}" type="number" min="0" step="0.01" :value="$user->max_credit_cents ? $user->max_credit_cents / 100 : null" hint="{{ __('Leave blank for the company default / no limit.') }}" prefix="$" />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <x-button :href="route('users.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        </x-card>

        @if ($user->type === \App\Enums\UserType::PayoutAgent)
            <x-card :title="__('Payout accounts')" :subtitle="__('Accounts this agent can pay receivers from.')">
                <div class="space-y-3 mb-5">
                    @forelse ($user->payoutAccounts as $account)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $account->accountType->name }} — {{ $account->number }}</p>
                                @if ($account->label)
                                    <p class="text-xs text-slate-500">{{ $account->label }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <x-badge :color="$account->is_active ? 'green' : 'slate'">
                                    {{ $account->is_active ? __('Active') : __('Disabled') }}
                                </x-badge>
                                @can('managePayoutAccounts', \App\Models\User::class)
                                    <form method="POST" action="{{ route('users.payout-accounts.toggle', [$user, $account]) }}">
                                        @csrf
                                        <x-button type="submit" variant="ghost" size="sm">
                                            {{ $account->is_active ? __('Disable') : __('Enable') }}
                                        </x-button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('No payout accounts yet.') }}</p>
                    @endforelse
                </div>

                @can('managePayoutAccounts', \App\Models\User::class)
                    <form method="POST" action="{{ route('users.payout-accounts.store', $user) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start border-t border-slate-100 pt-5">
                        @csrf
                        <x-select name="account_type_id" :options="$accountTypes" placeholder="{{ __('Account type') }}" />
                        <x-input name="number" placeholder="{{ __('Account number') }}" />
                        <div class="flex gap-2">
                            <x-input name="label" placeholder="{{ __('Label (optional)') }}" class="flex-1" />
                            <x-button type="submit" size="md">{{ __('Add') }}</x-button>
                        </div>
                    </form>
                @endcan
            </x-card>
        @endif
    </div>
</x-layouts.app>
