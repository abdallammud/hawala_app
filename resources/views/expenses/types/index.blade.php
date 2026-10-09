<x-layouts.app :title="__('Expense types')">
    @include('partials.expenses-tabs', ['active' => 'types'])

    <div class="max-w-2xl space-y-6">
        <x-card :title="__('Add a type')" :subtitle="__('Anything you spend money on regularly — it then appears when adding an expense.')">
            <form method="POST" action="{{ route('expense-types.store') }}" class="flex flex-col sm:flex-row gap-3 sm:items-start">
                @csrf
                <div class="flex-1">
                    <x-input name="name" placeholder="{{ __('e.g. Security guard') }}" />
                </div>
                <x-button type="submit">{{ __('Add type') }}</x-button>
            </form>
        </x-card>

        <x-card :padding="false">
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Expenses') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($types as $type)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Name') }}">{{ $type->name }}</td>
                        <td class="px-4 py-3 text-right text-slate-500" data-label="{{ __('Expenses') }}">{{ $type->expenses_count }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$type->is_active ? 'green' : 'slate'">{{ $type->is_active ? __('Active') : __('Inactive') }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            <a href="{{ route('expense-types.edit', $type) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Rename') }}</a>
                            <form method="POST" action="{{ route('expense-types.toggle', $type) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm font-medium {{ $type->is_active ? 'text-red-600 hover:text-red-700' : 'text-slate-600 hover:text-slate-700' }}">
                                    {{ $type->is_active ? __('Deactivate') : __('Activate') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
</x-layouts.app>
