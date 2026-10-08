<x-layouts.app :title="__('Branches')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    @error('branch')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <x-slot:actions>
            <x-button :href="route('setup.branches.create')" size="sm">
                <x-icon name="plus-circle" class="w-4 h-4" />
                {{ __('New branch') }}
            </x-button>
        </x-slot:actions>

        @if ($branches->isEmpty())
            <x-empty-state icon="building-office" title="{{ __('No branches yet') }}" description="{{ __('Create the first branch.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('City') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
                    <th class="px-4 py-3">{{ __('Users') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($branches as $branch)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Name') }}">{{ $branch->name }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('City') }}">{{ $branch->city }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Type') }}">{{ $branch->is_payout_point ? __('Payout point') : __('Office') }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Users') }}">{{ $branch->users_count }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$branch->is_active ? 'green' : 'slate'">
                                {{ $branch->is_active ? __('Active') : __('Inactive') }}
                            </x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            <a href="{{ route('setup.branches.edit', $branch) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('setup.branches.toggle', $branch) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm font-medium {{ $branch->is_active ? 'text-red-600 hover:text-red-700' : 'text-slate-600 hover:text-slate-700' }}">
                                    {{ $branch->is_active ? __('Deactivate') : __('Activate') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
