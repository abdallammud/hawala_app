<x-layouts.app :title="__('Company Accounts')">
    <x-card :padding="false">
        <x-slot:actions>
            @can('company_accounts.transfer')
                <x-button :href="route('company-accounts.transfer.create')" variant="secondary" size="sm">
                    {{ __('Move money') }}
                </x-button>
            @endcan
            @can('company_accounts.manage')
                <x-button :href="route('company-accounts.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('New account') }}
                </x-button>
            @endcan
        </x-slot:actions>

        @if ($accounts->isEmpty())
            <x-empty-state icon="building-library" title="{{ __('No company accounts yet') }}" description="{{ __('Create one, e.g. Europe office or Mogadishu bank.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Location') }}</th>
                    <th class="px-4 py-3">{{ __('Balance') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($accounts as $account)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Name') }}">
                            <a href="{{ route('company-accounts.show', $account) }}" class="hover:text-brand-600">{{ $account->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-500 capitalize" data-label="{{ __('Location') }}">{{ $account->location }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Balance') }}">
                            <x-money :cents="$account->ledgerAccount?->balance_cents ?? 0" />
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$account->is_active ? 'green' : 'slate'">
                                {{ $account->is_active ? __('Active') : __('Inactive') }}
                            </x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            <a href="{{ route('company-accounts.show', $account) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Statement') }}</a>
                            @can('company_accounts.manage')
                                <a href="{{ route('company-accounts.edit', $account) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                                <form method="POST" action="{{ route('company-accounts.toggle', $account) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-sm font-medium {{ $account->is_active ? 'text-red-600 hover:text-red-700' : 'text-slate-600 hover:text-slate-700' }}">
                                        {{ $account->is_active ? __('Deactivate') : __('Activate') }}
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
