<x-layouts.app :title="__('Account Types')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    @error('accountType')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <x-slot:actions>
            <x-button :href="route('setup.account-types.create')" size="sm">
                <x-icon name="plus-circle" class="w-4 h-4" />
                {{ __('New account type') }}
            </x-button>
        </x-slot:actions>

        @if ($accountTypes->isEmpty())
            <x-empty-state icon="wallet" title="{{ __('No account types yet') }}" description="{{ __('Create the first payout method, e.g. EVC Plus.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Sort') }}</th>
                    <th class="px-4 py-3">{{ __('Payout accounts') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($accountTypes as $accountType)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Name') }}">{{ $accountType->name }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Sort') }}">{{ $accountType->sort }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Payout accounts') }}">{{ $accountType->payout_accounts_count }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$accountType->is_active ? 'green' : 'slate'">
                                {{ $accountType->is_active ? __('Active') : __('Inactive') }}
                            </x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            <a href="{{ route('setup.account-types.edit', $accountType) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('setup.account-types.toggle', $accountType) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm font-medium {{ $accountType->is_active ? 'text-red-600 hover:text-red-700' : 'text-slate-600 hover:text-slate-700' }}">
                                    {{ $accountType->is_active ? __('Deactivate') : __('Activate') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
