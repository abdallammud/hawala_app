@php
    $rateAge = $data['rate_fetched_at']?->diffInHours(now());
    $rateStale = $data['rate_fetched_at'] === null
        || $rateAge > (int) \App\Support\Settings::get('currency.max_rate_age_hours', 72);
@endphp

<x-layouts.app :title="__('Dashboard')">

    {{-- Things that need a person to act. --}}
    @php
        $attention = collect([
            $data['pending_balance_requests'] ? ['label' => trans_choice(':count balance request waiting|:count balance requests waiting', $data['pending_balance_requests'], ['count' => $data['pending_balance_requests']]), 'route' => Route::has('balance-requests.inbox') ? route('balance-requests.inbox') : null] : null,
            $data['awaiting_approval'] ? ['label' => trans_choice(':count large transfer awaiting approval|:count large transfers awaiting approval', $data['awaiting_approval'], ['count' => $data['awaiting_approval']]), 'route' => route('transfers.index', ['status' => 'awaiting_approval'])] : null,
            $data['pending_cancellations'] ? ['label' => trans_choice(':count cancellation request waiting|:count cancellation requests waiting', $data['pending_cancellations'], ['count' => $data['pending_cancellations']]), 'route' => Route::has('cancellation-requests.index') ? route('cancellation-requests.index') : null] : null,
            $data['expired_today'] ? ['label' => trans_choice(':count claim timed out today|:count claims timed out today', $data['expired_today'], ['count' => $data['expired_today']]), 'route' => route('queue.index')] : null,
            $rateStale ? ['label' => __('Exchange rates are stale — non-USD transfers will be blocked'), 'route' => Route::has('setup.currencies.index') ? route('setup.currencies.index') : null] : null,
        ])->filter();
    @endphp

    @if ($attention->isNotEmpty())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5">
            <div class="flex items-center gap-2 mb-3">
                <span class="rounded-lg bg-amber-500 text-white p-1.5 shadow-sm">
                    <x-icon name="exclamation-triangle" class="w-4 h-4" />
                </span>
                <h3 class="text-sm font-semibold text-amber-900">{{ __('Needs attention') }}</h3>
            </div>
            <ul class="space-y-1.5">
                @foreach ($attention as $item)
                    <li class="flex items-center gap-2.5 text-sm">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                        @if ($item['route'])
                            <a href="{{ $item['route'] }}" class="font-medium text-amber-900 underline decoration-amber-300 hover:decoration-amber-600">{{ $item['label'] }}</a>
                        @else
                            <span class="font-medium text-amber-900">{{ $item['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat
            label="{{ __('Money available in Somalia') }}"
            :value="\App\Support\Money::format($data['somalia_available'])"
            icon="building-library" tone="brand"
            trend="{{ __(':accounts in accounts · :float agent float', ['accounts' => \App\Support\Money::format($data['somalia_company']), 'float' => \App\Support\Money::format($data['payout_float'])]) }}" />
        <x-stat label="{{ __('Waiting to be paid') }}" :value="\App\Support\Money::format($data['waiting_to_pay'])" icon="inbox-stack" tone="gold" />
        <x-stat label="{{ __('Uncollected') }}" :value="\App\Support\Money::format($data['uncollected_total'])" icon="exclamation-triangle" :tone="$data['uncollected_total'] > 0 ? 'amber' : 'slate'" />
        <x-stat label="{{ __('Agents owing credit') }}" :value="$data['agents_negative_credit']" icon="banknotes" :tone="$data['agents_negative_credit'] > 0 ? 'red' : 'slate'" />
    </div>

    <x-card :title="__('Today')">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ __('Transfers') }}</p>
                <p class="text-xl sm:text-2xl font-bold text-brand-900 mt-1">{{ $data['today_count'] }}</p>
            </div>
            <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ __('Volume') }}</p>
                <p class="text-xl sm:text-2xl font-bold text-brand-900 mt-1 font-mono">{{ \App\Support\Money::format($data['today_volume']) }}</p>
            </div>
            <div class="rounded-lg bg-gold-50 border border-gold-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gold-700">{{ __('Fees') }}</p>
                <p class="text-xl sm:text-2xl font-bold text-gold-900 mt-1 font-mono">{{ \App\Support\Money::format($data['today_fees']) }}</p>
            </div>
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ __('Profit after commission') }}</p>
                <p class="text-xl sm:text-2xl font-bold text-emerald-900 mt-1 font-mono">{{ \App\Support\Money::format($data['today_profit']) }}</p>
            </div>
        </div>

        <div class="mt-5 pt-5 border-t border-slate-100 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-slate-500">
            <span>
                {{ __('Rates') }}:
                @if ($data['rate_fetched_at'])
                    <span class="{{ $rateStale ? 'text-red-600 font-medium' : 'text-slate-700' }}">{{ $data['rate_fetched_at']->diffForHumans() }}</span>
                @else
                    <span class="text-red-600 font-medium">{{ __('never fetched') }}</span>
                @endif
            </span>
            <span>{{ __('Large transfers this month') }}: <span class="text-slate-700">{{ $data['large_this_month'] }}</span></span>
        </div>
    </x-card>

    <x-card :title="__('Recent transfers')" :padding="false">
        <x-slot:actions>
            <x-button :href="route('transfers.index')" variant="ghost" size="sm">{{ __('See all') }}</x-button>
        </x-slot:actions>

        @if ($data['recent']->isEmpty())
            <x-empty-state icon="arrow-right-left" title="{{ __('No transfers yet') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Code') }}</th>
                    <th class="px-4 py-3">{{ __('Agent') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($data['recent'] as $transfer)
                    <tr>
                        <td class="px-4 py-3" data-label="{{ __('Code') }}">
                            <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Agent') }}">{{ $transfer->sendingAgent->name }}</td>
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
