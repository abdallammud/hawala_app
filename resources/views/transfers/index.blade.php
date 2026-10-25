<x-layouts.app :title="__('Transfers')">
    <x-card :padding="false">
        <x-slot:actions>
            @can('create', \App\Models\Transfer::class)
                <x-button :href="route('transfers.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('New transfer') }}
                </x-button>
            @endcan
        </x-slot:actions>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <x-input name="search" placeholder="{{ __('Code, sender, receiver or phone') }}" :value="request('search')" />
                </div>
                <x-select name="status" :options="$statuses" placeholder="{{ __('All statuses') }}" :value="request('status')" class="sm:w-44" />
                <x-select name="branch_id" :options="$branches" placeholder="{{ __('All branches') }}" :value="request('branch_id')" class="sm:w-40" />
                <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                @if (request()->hasAny(['search', 'status', 'branch_id', 'uncollected', 'large']))
                    <x-button :href="route('transfers.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($transfers->isEmpty())
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers found') }}" description="{{ __('Try a different search or create the first transfer.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Tracking number') }}</th>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($transfers as $transfer)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Tracking number') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="hover:text-brand-600">{{ $transfer->code }}</a>
                            @if ($transfer->is_large)
                                <x-badge color="gold" class="ml-1">{{ __('Large') }}</x-badge>
                            @endif
                            @if ($transfer->is_uncollected && ! $transfer->fully_collected_at)
                                <x-badge color="amber" class="ml-1">{{ __('Uncollected') }}</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $transfer->created_at->toLocal()->format('M j, Y') }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Receiver') }}">
                            <div class="text-slate-900">{{ $transfer->receiver_name }}</div>
                            <div class="text-xs text-slate-500">{{ $transfer->receiver_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Branch') }}">{{ $transfer->branch->name }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}"><x-money :cents="$transfer->amount_cents" /></td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$transfer->status->color()">{{ $transfer->status->label() }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-right" data-label="">
                            <a href="{{ route('transfers.show', $transfer) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('View') }}</a>
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$transfers" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
