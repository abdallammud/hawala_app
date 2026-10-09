<x-layouts.app :title="__('Dashboard')">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Good day, :name', ['name' => auth()->user()->name]) }}</h2>
            <p class="text-sm text-slate-500">
                {{ trans_choice(':count transfer sent today|:count transfers sent today', $sending['today_count'], ['count' => $sending['today_count']]) }}
                — <x-money :cents="$sending['today_amount']" />
                · {{ trans_choice(':count payment made today|:count payments made today', $payout['paid_today'], ['count' => $payout['paid_today']]) }}
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            @can('transfers.create')
                <x-button :href="route('transfers.create')" size="lg">
                    <x-icon name="plus-circle" class="w-5 h-5" />
                    {{ __('New transfer') }}
                </x-button>
            @endcan
            @can('queue.view')
                <x-button :href="route('queue.index')" variant="secondary" size="lg">
                    <x-icon name="inbox-stack" class="w-5 h-5" />
                    {{ __('Open queue') }}
                </x-button>
            @endcan
        </div>
    </div>

    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">{{ __('Sending') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat label="{{ __('Send balance') }}" :value="\App\Support\Money::format($sending['send_balance'])" icon="wallet" tone="brand" :href="\App\Support\ReportLink::to('agent-statement', ['agent_id' => auth()->id(), 'account_type' => 'agent_send'])" />
            <x-stat label="{{ __('Credit owed') }}" :value="\App\Support\Money::format($sending['credit_owed'])" icon="banknotes" :tone="$sending['credit_owed'] > 0 ? 'red' : 'slate'" :href="\App\Support\ReportLink::to('agent-statement', ['agent_id' => auth()->id(), 'account_type' => 'agent_credit'])" />
            <x-stat label="{{ __('Commission') }}" :value="\App\Support\Money::format($sending['commission'])" icon="gift" tone="gold" :href="\App\Support\ReportLink::to('commissions')" />
            <x-stat label="{{ __('Uncollected') }}" :value="\App\Support\Money::format($sending['uncollected'])" icon="exclamation-triangle" :tone="$sending['uncollected'] > 0 ? 'amber' : 'slate'" :href="\App\Support\ReportLink::to('uncollected', ['agent_id' => auth()->id()], allTime: true)" />
        </div>
    </div>

    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">{{ __('Paying out') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-stat label="{{ __('Payout balance') }}" :value="\App\Support\Money::format($payout['payout_balance'])" icon="wallet" tone="brand" :href="\App\Support\ReportLink::to('agent-statement', ['agent_id' => auth()->id(), 'account_type' => 'agent_payout'])" />
            <x-stat label="{{ __('Waiting in my branch') }}" :value="$payout['queue_count']" icon="inbox-stack" :trend="\App\Support\Money::format($payout['queue_amount'])" tone="slate" :href="auth()->user()->can('queue.view') ? route('queue.index') : null" />
            <x-stat label="{{ __('Claimed by me') }}" :value="$payout['claimed']->count()" icon="check-circle" tone="green" :href="auth()->user()->can('queue.view') ? route('queue.index') : null" />
        </div>
    </div>

    <x-card :title="__('My claimed transfers')" :padding="false">
        @if ($payout['claimed']->isEmpty())
            <x-empty-state icon="check-circle" title="{{ __('Nothing claimed') }}" description="{{ __('Open the queue to claim a transfer and pay it.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Tracking number') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Expires') }}</th>
                </x-slot:head>

                @foreach ($payout['claimed'] as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Tracking number') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Receiver') }}">{{ $transfer->receiver_name }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}"><x-money :cents="$transfer->amount_cents" /></td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Expires') }}">{{ $transfer->claim_expires_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    <x-card :title="__('Recent transfers')" :padding="false">
        <x-slot:actions>
            <x-button :href="route('transfers.index')" variant="ghost" size="sm">{{ __('See all') }}</x-button>
        </x-slot:actions>

        @if ($sending['recent']->isEmpty())
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers yet') }}" description="{{ __('Your transfers will appear here.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Tracking number') }}</th>
                    <th class="px-4 py-3">{{ __('Receiver') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($sending['recent'] as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Tracking number') }}">
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
