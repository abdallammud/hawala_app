<x-layouts.app :title="__('Dashboard')">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Good day, :name', ['name' => auth()->user()->name]) }}</h2>
            <p class="text-sm text-slate-500">
                {{ trans_choice(':count payment made today|:count payments made today', $data['paid_today'], ['count' => $data['paid_today']]) }}
            </p>
        </div>
        @can('queue.view')
            <x-button :href="route('queue.index')" size="lg">
                <x-icon name="inbox-stack" class="w-5 h-5" />
                {{ __('Open queue') }}
            </x-button>
        @endcan
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="{{ __('Payout balance') }}" :value="\App\Support\Money::format($data['payout_balance'])" icon="wallet" tone="brand" />
        <x-stat label="{{ __('Commission') }}" :value="\App\Support\Money::format($data['commission'])" icon="gift" tone="gold" />
        <x-stat label="{{ __('Waiting in my branch') }}" :value="$data['queue_count']" icon="inbox-stack" :trend="\App\Support\Money::format($data['queue_amount'])" tone="slate" />
        <x-stat label="{{ __('Claimed by me') }}" :value="$data['claimed']->count()" icon="check-circle" tone="green" />
    </div>

    <x-card :title="__('My claimed transfers')" :padding="false">
        @if ($data['claimed']->isEmpty())
            <x-empty-state icon="check-circle" title="{{ __('Nothing claimed') }}" description="{{ __('Open the queue to claim a transfer and pay it.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Code') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Expires') }}</th>
                </x-slot:head>

                @foreach ($data['claimed'] as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Code') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Receiver') }}">
                            {{ $transfer->receiver_name }}
                            <div class="text-xs text-slate-500">{{ $transfer->receiver_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}"><x-money :cents="$transfer->amount_cents" /></td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Expires') }}">
                            {{ $transfer->claim_expires_at?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</x-layouts.app>
