<x-layouts.app :title="__('Payout Queue')">
    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    @if ($payoutBalance !== null)
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-stat label="{{ __('Payout balance') }}" :value="\App\Support\Money::format($payoutBalance)" icon="wallet" tone="brand" />
            <x-stat label="{{ __('Waiting in queue') }}" :value="$queued->count()" icon="inbox-stack" tone="gold" />
            <x-stat label="{{ __('Claimed by me') }}" :value="$claimed->count()" icon="check-circle" tone="green" />
        </div>
    @endif

    {{-- Claimed first: these have a running clock. --}}
    <x-card :title="$isAdmin ? __('Claimed') : __('My claimed transfers')" :padding="false">
        @if ($claimed->isEmpty())
            <x-empty-state icon="check-circle" title="{{ __('Nothing claimed') }}" description="{{ __('Claim a transfer below to start paying it.') }}" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($claimed as $transfer)
                    <div class="p-5" x-data="{
                        expiresAt: {{ $transfer->claim_expires_at?->timestamp ?? 0 }} * 1000,
                        now: Date.now(),
                        get remaining() { return Math.max(0, Math.floor((this.expiresAt - this.now) / 1000)); },
                        get label() {
                            if (!this.expiresAt) return '';
                            const m = Math.floor(this.remaining / 60), s = this.remaining % 60;
                            return m + ':' + String(s).padStart(2, '0');
                        },
                        get urgent() { return this.remaining > 0 && this.remaining < 300; },
                    }" x-init="setInterval(() => now = Date.now(), 1000)">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                                    <span class="font-mono text-sm text-slate-900">{{ \App\Support\Money::format($transfer->amount_cents) }}</span>
                                    @if ($transfer->assignedBy)
                                        <x-badge color="brand">{{ __('Assigned') }}</x-badge>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-600 mt-1">
                                    {{ $transfer->receiver_name }} — {{ $transfer->receiver_phone }}
                                </p>
                                @if ($isAdmin)
                                    <p class="text-xs text-slate-500 mt-0.5">{{ __('Claimed by :who', ['who' => $transfer->claimedBy?->name]) }}</p>
                                @endif
                                @if ($transfer->receiverAccountType)
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        {{ __('Prefers') }}: {{ $transfer->receiverAccountType->name }}
                                        @if ($transfer->receiver_account_number) — {{ $transfer->receiver_account_number }} @endif
                                    </p>
                                @endif
                            </div>

                            <div class="flex items-center gap-3 shrink-0">
                                @if ($transfer->claim_expires_at)
                                    <span class="text-sm font-mono" :class="urgent ? 'text-red-600 font-semibold' : 'text-slate-500'">
                                        <span x-text="label"></span>
                                    </span>
                                @endif

                                @can('markPaid', $transfer)
                                    <x-button type="button" size="sm"
                                        x-data x-on:click="$dispatch('open-modal', 'pay-{{ $transfer->id }}')">
                                        {{ __('Mark paid') }}
                                    </x-button>
                                @endcan

                                @can('unassign', $transfer)
                                    <form method="POST" action="{{ route('queue.release', $transfer) }}">
                                        @csrf
                                        <x-button type="submit" variant="ghost" size="sm">{{ __('Release') }}</x-button>
                                    </form>
                                @endcan
                            </div>
                        </div>

                        @can('markPaid', $transfer)
                            <x-modal name="pay-{{ $transfer->id }}" title="{{ __('Mark :code paid', ['code' => $transfer->code]) }}">
                                <form method="POST" action="{{ route('queue.mark-paid', $transfer) }}" class="space-y-4">
                                    @csrf
                                    <div class="rounded-lg bg-slate-50 p-3 text-center">
                                        <p class="text-xs text-slate-500">{{ __('Pay the receiver') }}</p>
                                        <p class="text-2xl font-semibold text-slate-900 font-mono">{{ \App\Support\Money::format($transfer->amount_cents) }}</p>
                                        <p class="text-sm text-slate-600 mt-1">{{ $transfer->receiver_name }} — {{ $transfer->receiver_phone }}</p>
                                    </div>

                                    @php
                                        $accounts = $isAdmin && $transfer->claimedBy
                                            ? $transfer->claimedBy->payoutAccounts()->where('is_active', true)->with('accountType')->get()
                                            : $payoutAccounts;
                                    @endphp

                                    @if ($accounts->isEmpty())
                                        <x-alert type="warning">{{ __('No active payout accounts — add one under Users before marking transfers paid.') }}</x-alert>
                                    @else
                                        <x-select
                                            name="payout_account_id"
                                            label="{{ __('Paid from') }}"
                                            :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->accountType->name.' — '.$a->number])"
                                        />
                                        <x-input name="payout_reference" label="{{ __('Reference') }}"
                                            hint="{{ \App\Support\Settings::get('payout.reference_required', false) ? __('Required.') : __('Optional.') }}" />
                                        <div class="flex justify-end">
                                            <x-button type="submit">{{ __('Confirm paid') }}</x-button>
                                        </div>
                                    @endif
                                </form>
                            </x-modal>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card :title="__('Waiting in the queue')" :padding="false">
        @if ($queued->isEmpty())
            <x-empty-state icon="inbox-stack" title="{{ __('Queue is empty') }}" description="{{ __('New transfers to your branch will appear here.') }}" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($queued as $transfer)
                    <div class="p-5 flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('transfers.show', $transfer) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $transfer->code }}</a>
                                <span class="font-mono text-sm text-slate-900">{{ \App\Support\Money::format($transfer->amount_cents) }}</span>
                                @if ($transfer->is_large)
                                    <x-badge color="gold">{{ __('Large') }}</x-badge>
                                @endif
                            </div>
                            <p class="text-sm text-slate-600 mt-1">{{ $transfer->receiver_name }} — {{ $transfer->receiver_phone }}</p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $transfer->branch->name }} · {{ __('from :agent', ['agent' => $transfer->sendingAgent->name]) }} · {{ $transfer->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            @can('claim', $transfer)
                                <form method="POST" action="{{ route('queue.claim', $transfer) }}">
                                    @csrf
                                    <x-button type="submit" size="sm">{{ __('Claim') }}</x-button>
                                </form>
                            @endcan

                            @can('forceAssign', \App\Models\Transfer::class)
                                <x-button type="button" variant="secondary" size="sm"
                                    x-data x-on:click="$dispatch('open-modal', 'assign-{{ $transfer->id }}')">
                                    {{ __('Assign') }}
                                </x-button>

                                <x-modal name="assign-{{ $transfer->id }}" title="{{ __('Assign :code', ['code' => $transfer->code]) }}">
                                    @php $eligible = $branchAgents->where('branch_id', $transfer->branch_id); @endphp
                                    <form method="POST" action="{{ route('queue.force-assign', $transfer) }}" class="space-y-4">
                                        @csrf
                                        @if ($eligible->isEmpty())
                                            <x-alert type="warning">{{ __('No active payout agents in :branch.', ['branch' => $transfer->branch->name]) }}</x-alert>
                                        @else
                                            <x-select name="user_id" label="{{ __('Payout agent') }}" :options="$eligible->pluck('name', 'id')" />
                                            <div class="flex justify-end">
                                                <x-button type="submit">{{ __('Assign') }}</x-button>
                                            </div>
                                        @endif
                                    </form>
                                </x-modal>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>
</x-layouts.app>
