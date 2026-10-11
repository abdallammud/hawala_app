<x-layouts.app :title="$transfer->code">
    <a href="{{ route('transfers.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Transfers') }}
    </a>

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">

            <x-card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Tracking number') }}</p>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-semibold text-slate-900">{{ $transfer->code }}</h2>
                            <x-badge :color="$transfer->status->color()">{{ $transfer->status->label() }}</x-badge>
                            @if ($transfer->is_large)
                                <x-badge color="gold">{{ __('Large') }}</x-badge>
                            @endif
                        </div>
                        <p class="text-sm text-slate-500 mt-1">
                            {{ __('Created :when by :agent', ['when' => $transfer->created_at->format('M j, Y H:i'), 'agent' => $transfer->sendingAgent->name]) }}
                        </p>
                    </div>
                    <div class="flex items-start gap-3 shrink-0">
                        <div class="text-right">
                            <p class="text-2xl font-semibold text-slate-900 font-mono">{{ \App\Support\Money::format($transfer->amount_cents) }}</p>
                            <p class="text-xs text-slate-500">{{ __('to :branch', ['branch' => $transfer->branch->name]) }}</p>
                        </div>
                        {{-- Always there, whatever the receipt switch says. --}}
                        <a href="{{ route('transfers.print', $transfer) }}" target="_blank" rel="noopener"
                           title="{{ __('Print receipt') }}" aria-label="{{ __('Print receipt') }}"
                           class="p-2.5 rounded-lg border border-slate-300 text-slate-600 hover:text-brand-700 hover:bg-slate-50 print:hidden">
                            <x-icon name="printer" class="w-5 h-5" />
                        </a>
                    </div>
                </div>

                {{-- Receipts stay hidden until an admin turns receipts.enabled on. --}}
                @if ($receipts->enabled())
                    <div class="mt-4 pt-4 border-t border-slate-100 flex flex-wrap gap-2">
                        <x-button :href="route('transfers.receipt', $transfer)" variant="secondary" size="sm">
                            <x-icon name="document-text" class="w-4 h-4" />
                            {{ __('Download receipt') }}
                        </x-button>
                        <x-button
                            :href="$receipts->whatsappUrl($transfer)"
                            variant="secondary"
                            size="sm"
                            target="_blank"
                            rel="noopener"
                        >
                            <x-icon name="chat-bubble-left-right" class="w-4 h-4" />
                            {{ __('Share via WhatsApp') }}
                        </x-button>
                    </div>
                @endif
            </x-card>

            <x-card :title="__('Parties')">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Sender') }}</p>
                        <p class="text-slate-900 mt-1">{{ $transfer->sender_name }}</p>
                        <p class="text-slate-500">{{ $transfer->sender_phone }}</p>
                        @if ($location = collect([$transfer->sender_city, $transfer->sender_province, $transfer->sender_country])->filter()->implode(', '))
                            <p class="text-slate-500">{{ $location }}</p>
                        @endif
                        @if ($transfer->customer_id && auth()->user()->can('view', $transfer->customer))
                            <a href="{{ route('customers.show', $transfer->customer_id) }}" class="text-xs text-brand-600 hover:text-brand-700">{{ __('Customer profile') }}</a>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Receiver') }}</p>
                        <p class="text-slate-900 mt-1">{{ $transfer->receiver_name }}</p>
                        <p class="text-slate-500">{{ $transfer->receiver_phone }}</p>
                        @if ($location = collect([$transfer->receiver_city, $transfer->receiver_province, $transfer->receiver_country])->filter()->implode(', '))
                            <p class="text-slate-500">{{ $location }}</p>
                        @endif
                        @if ($transfer->receiverAccountType)
                            <p class="text-slate-500 mt-1">
                                {{ $transfer->receiverAccountType->name }}
                                @if ($transfer->receiver_account_number) — {{ $transfer->receiver_account_number }} @endif
                            </p>
                        @endif
                    </div>
                </div>
                @if ($transfer->note)
                    <div class="mt-5 pt-5 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Note') }}</p>
                        <p class="text-sm text-slate-700 mt-1">{{ $transfer->note }}</p>
                    </div>
                @endif
            </x-card>

            <x-card :title="__('Money')">
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500">{{ __('Quoted on') }}</dt>
                        <dd class="text-slate-900">{{ $transfer->quote_mode?->label() ?? __('Customer pays') }}</dd>
                    </div>
                    @if ($transfer->original_currency !== 'USD')
                        <div class="flex justify-between py-2.5">
                            <dt class="text-slate-500">{{ __('Original amount') }}</dt>
                            <dd class="font-mono text-slate-900">{{ number_format($transfer->original_amount_cents / 100, 2) }} {{ $transfer->original_currency }}</dd>
                        </div>
                        <div class="flex justify-between py-2.5">
                            <dt class="text-slate-500">{{ __('Rate used') }}</dt>
                            <dd class="font-mono text-slate-900">
                                {{ rtrim(rtrim($transfer->customer_rate, '0'), '.') }}
                                @if ($transfer->margin_bp > 0)
                                    <span class="text-xs text-slate-400">({{ __('market') }} {{ rtrim(rtrim($transfer->market_rate, '0'), '.') }})</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500">{{ __('Receiver gets') }}</dt>
                        <dd class="font-mono text-slate-900"><x-money :cents="$transfer->amount_cents" /></dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500">{{ __('Fee') }}</dt>
                        <dd class="font-mono text-slate-900"><x-money :cents="$transfer->fee_cents" /></dd>
                    </div>
                    @if ($transfer->fx_income_cents > 0)
                        <div class="flex justify-between py-2.5">
                            <dt class="text-slate-500">{{ __('FX income') }}</dt>
                            <dd class="font-mono text-slate-900"><x-money :cents="$transfer->fx_income_cents" /></dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500">{{ __('Collected from customer') }}</dt>
                        <dd class="font-mono text-slate-900">{{ number_format($transfer->collect_original_cents / 100, 2) }} {{ $transfer->original_currency }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5 font-medium">
                        <dt class="text-slate-700">{{ __('Taken from agent balance') }}</dt>
                        <dd class="font-mono text-slate-900"><x-money :cents="$transfer->total_debit_cents" /></dd>
                    </div>
                </dl>

                @can('transfers.view_all')
                    <div class="mt-5 pt-5 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-2">{{ __('Commission split') }}</p>
                        <dl class="divide-y divide-slate-100 text-sm">
                            <div class="flex justify-between py-2">
                                <dt class="text-slate-500">
                                    {{ __('Sending agent') }}
                                    @if (data_get($transfer->commission_snapshot, 'sending.capped'))
                                        <x-badge color="amber" class="ml-1">{{ __('capped') }}</x-badge>
                                    @endif
                                </dt>
                                <dd class="font-mono text-slate-900"><x-money :cents="$transfer->sending_share_cents" /></dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-slate-500">
                                    {{ __('Payout agent') }}
                                    @if (data_get($transfer->commission_snapshot, 'payout.capped'))
                                        <x-badge color="amber" class="ml-1">{{ __('capped') }}</x-badge>
                                    @endif
                                </dt>
                                <dd class="font-mono text-slate-900">
                                    {{ $transfer->payout_share_cents === null ? '—' : \App\Support\Money::format($transfer->payout_share_cents) }}
                                </dd>
                            </div>
                            <div class="flex justify-between py-2">
                                <dt class="text-slate-500">{{ __('Company') }}</dt>
                                <dd class="font-mono text-slate-900">
                                    {{ $transfer->company_share_cents === null ? '—' : \App\Support\Money::format($transfer->company_share_cents) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endcan
            </x-card>

            @if ($transfer->is_uncollected)
                <x-card :title="__('Uncollected')" :subtitle="__('Money sent before the customer paid.')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-xs text-slate-500">{{ __('Outstanding') }}</p>
                            <p class="text-lg font-semibold text-slate-900 font-mono">{{ \App\Support\Money::format($transfer->outstandingCents()) }}</p>
                        </div>
                        @if ($transfer->fully_collected_at)
                            <x-badge color="green">{{ __('Fully collected') }}</x-badge>
                        @endif
                    </div>

                    @if ($transfer->collections->isNotEmpty())
                        <div class="space-y-2 mb-5">
                            @foreach ($transfer->collections as $collection)
                                <div class="flex items-center justify-between text-sm rounded-lg border border-slate-200 px-3 py-2">
                                    <div>
                                        <span class="font-mono text-slate-900">{{ \App\Support\Money::format($collection->amount_cents) }}</span>
                                        @if ($collection->currency !== 'USD')
                                            <span class="text-xs text-slate-400">({{ number_format($collection->original_amount_cents / 100, 2) }} {{ $collection->currency }})</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ $collection->received_at->format('M j, Y') }} — {{ $collection->recordedBy->name }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @can('recordCollection', $transfer)
                        <form method="POST" action="{{ route('transfers.collections.store', $transfer) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end border-t border-slate-100 pt-5">
                            @csrf
                            <x-input name="amount" label="{{ __('Amount') }}" type="number" min="0.01" step="0.01" />
                            <x-select name="currency" label="{{ __('Currency') }}" :options="$currencies" :value="$transfer->original_currency" />
                            <x-input name="received_at" label="{{ __('Received') }}" type="date" :value="now()->toDateString()" />
                            <x-button type="submit">{{ __('Record') }}</x-button>
                        </form>
                    @endcan
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-card :title="__('Timeline')">
                <ol class="space-y-4">
                    @php
                        $events = collect([
                            ['at' => $transfer->created_at, 'label' => __('Created'), 'who' => $transfer->sendingAgent->name, 'color' => 'bg-brand-500'],
                        ]);

                        if ($transfer->claimed_at) {
                            $events->push([
                                'at' => $transfer->claimed_at,
                                'label' => $transfer->assignedBy ? __('Assigned') : __('Claimed'),
                                'who' => $transfer->claimedBy?->name.($transfer->assignedBy ? ' ('.__('by :admin', ['admin' => $transfer->assignedBy->name]).')' : ''),
                                'color' => 'bg-gold-500',
                            ]);
                        }
                        if ($transfer->paid_at) {
                            $events->push(['at' => $transfer->paid_at, 'label' => __('Paid'), 'who' => $transfer->paidBy?->name, 'color' => 'bg-emerald-500']);
                        }
                        if ($transfer->cancelled_at) {
                            $events->push([
                                'at' => $transfer->cancelled_at,
                                'label' => $transfer->status === \App\Enums\TransferStatus::Rejected ? __('Rejected') : __('Cancelled'),
                                'who' => $transfer->cancelledBy?->name,
                                'color' => 'bg-red-500',
                            ]);
                        }
                    @endphp

                    @foreach ($events->sortBy('at') as $event)
                        <li class="flex gap-3">
                            <div class="mt-1.5 h-2 w-2 rounded-full shrink-0 {{ $event['color'] }}"></div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $event['label'] }}</p>
                                <p class="text-xs text-slate-500">{{ $event['at']->format('M j, Y H:i') }}</p>
                                @if ($event['who'])
                                    <p class="text-xs text-slate-500">{{ $event['who'] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($transfer->cancel_reason)
                    <div class="mt-5 pt-5 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Reason') }}</p>
                        <p class="text-sm text-slate-700 mt-1">{{ $transfer->cancel_reason }}</p>
                        <p class="text-xs text-slate-500 mt-2">
                            {{ $transfer->fee_refunded ? __('Fee was refunded.') : __('Fee was kept by the company.') }}
                        </p>
                    </div>
                @endif

                @if ($transfer->status === \App\Enums\TransferStatus::Paid && $transfer->payoutAccount)
                    <div class="mt-5 pt-5 border-t border-slate-100">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Paid via') }}</p>
                        <p class="text-sm text-slate-700 mt-1">
                            {{ $transfer->payoutAccount->accountType->name }} — {{ $transfer->payoutAccount->number }}
                        </p>
                        @if ($transfer->payout_reference)
                            <p class="text-xs text-slate-500">{{ __('Ref: :ref', ['ref' => $transfer->payout_reference]) }}</p>
                        @endif
                    </div>
                @endif
            </x-card>

            @include('transfers.partials.actions', ['transfer' => $transfer])
        </div>
    </div>
</x-layouts.app>
