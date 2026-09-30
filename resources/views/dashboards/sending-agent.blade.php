<x-layouts.app :title="__('Dashboard')">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Good day, :name', ['name' => auth()->user()->name]) }}</h2>
            <p class="text-sm text-slate-500">
                {{ trans_choice(':count transfer sent today|:count transfers sent today', $data['today_count'], ['count' => $data['today_count']]) }}
                — <x-money :cents="$data['today_amount']" />
            </p>
        </div>
        @can('transfers.create')
            <x-button :href="route('transfers.create')" size="lg">
                <x-icon name="plus-circle" class="w-5 h-5" />
                {{ __('New transfer') }}
            </x-button>
        @endcan
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="{{ __('Send balance') }}" :value="\App\Support\Money::format($data['send_balance'])" icon="wallet" tone="brand" />
        <x-stat label="{{ __('Credit owed') }}" :value="\App\Support\Money::format($data['credit_owed'])" icon="banknotes" :tone="$data['credit_owed'] > 0 ? 'red' : 'slate'" />
        <x-stat label="{{ __('Commission') }}" :value="\App\Support\Money::format($data['commission'])" icon="gift" tone="gold" />
        <x-stat label="{{ __('Uncollected') }}" :value="\App\Support\Money::format($data['uncollected'])" icon="exclamation-triangle" :tone="$data['uncollected'] > 0 ? 'amber' : 'slate'" />
    </div>

    <x-card :title="__('Recent transfers')" :padding="false">
        <x-slot:actions>
            <x-button :href="route('transfers.index')" variant="ghost" size="sm">{{ __('See all') }}</x-button>
        </x-slot:actions>

        @if ($data['recent']->isEmpty())
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers yet') }}" description="{{ __('Your transfers will appear here.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Code') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($data['recent'] as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Code') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Receiver') }}">{{ $transfer->receiver_name }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Branch') }}">{{ $transfer->branch->name }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}"><x-money :cents="$transfer->amount_cents" /></td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$transfer->status->color()">{{ $transfer->status->label() }}</x-badge>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
