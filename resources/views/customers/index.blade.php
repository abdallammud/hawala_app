<x-layouts.app :title="__('Customers')">
    <x-card :padding="false">
        <x-slot:actions>
            @can('transfers.create')
                <x-button :href="route('transfers.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('New transfer') }}
                </x-button>
            @endcan
        </x-slot:actions>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <x-input name="search" placeholder="{{ __('Search name or phone') }}" :value="request('search')" />
                </div>
                <x-button type="submit" variant="secondary">{{ __('Search') }}</x-button>
                @if (request()->filled('search'))
                    <x-button :href="route('customers.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($customers->isEmpty())
            <x-empty-state icon="users" title="{{ __('No customers found') }}" description="{{ __('Customers are added automatically when you send their first transfer.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Phone') }}</th>
                    <th class="px-4 py-3">{{ __('Location') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Transfers') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Total sent') }}</th>
                </x-slot:head>

                @foreach ($customers as $customer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Name') }}">
                            <a href="{{ route('customers.show', $customer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $customer->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Phone') }}">{{ $customer->phoneLabel() }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Location') }}">{{ $customer->location() ?: '—' }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Transfers') }}">{{ $customer->transfers_count }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Total sent') }}"><x-money :cents="(int) $customer->sent_cents" /></td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$customers" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
