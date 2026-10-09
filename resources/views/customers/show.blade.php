<x-layouts.app :title="$customer->name">
    <a href="{{ route('customers.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Customers') }}
    </a>

    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $customer->name }}</h2>
                <p class="text-sm text-slate-600 mt-1">{{ $customer->phoneLabel() }}</p>
                <p class="text-sm text-slate-500">{{ $customer->location() ?: __('No location recorded') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('transfers.create')
                    <x-button :href="route('transfers.create', ['customer' => $customer->id])" size="sm">
                        <x-icon name="plus-circle" class="w-4 h-4" />
                        {{ __('New transfer') }}
                    </x-button>
                @endcan
                @can('update', $customer)
                    <x-button :href="route('customers.edit', $customer)" variant="secondary" size="sm">{{ __('Edit') }}</x-button>
                @endcan
                @can('reports.customers')
                    <x-button :href="route('reports.show', ['report' => 'customer-statement', 'customer_id' => $customer->id, 'from' => '2000-01-01', 'to' => today()->toDateString()])" variant="secondary" size="sm">
                        {{ __('Statement report') }}
                    </x-button>
                @endcan
            </div>
        </div>
    </x-card>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="{{ __('Transfers') }}" :value="$stats['count']" icon="arrow-right-left" tone="brand" />
        <x-stat label="{{ __('Total sent') }}" :value="\App\Support\Money::format($stats['sent'])" icon="wallet" tone="brand" />
        <x-stat label="{{ __('Fees paid') }}" :value="\App\Support\Money::format($stats['fees'])" icon="gift" tone="gold" />
        <x-stat label="{{ __('Last transfer') }}" :value="$stats['last'] ? \Carbon\Carbon::parse($stats['last'])->diffForHumans() : '—'" icon="inbox-stack" tone="slate" />
    </div>

    <x-card :title="__('Transfers')" :padding="false">
        @if ($transfers->isEmpty())
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers yet') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Tracking number') }}</th>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($transfers as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Tracking number') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $transfer->created_at->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Receiver') }}">
                            {{ $transfer->receiver_name }}
                            @if ($transfer->receiver_city)
                                <div class="text-xs text-slate-500">{{ $transfer->receiver_city }}@if ($transfer->receiver_country), {{ $transfer->receiver_country }}@endif</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Branch') }}">{{ $transfer->branch->name }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}"><x-money :cents="$transfer->amount_cents" /></td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$transfer->status->color()">{{ $transfer->status->label() }}</x-badge>
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
