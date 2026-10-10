<x-layouts.app :title="__('New Transfer')">
    <div class="max-w-2xl space-y-6">

        @if (session('duplicate_warning'))
            <x-alert type="warning" title="{{ __('Possible duplicate') }}">
                {{ session('duplicate_warning') }}
            </x-alert>
        @endif

        @if (session('insufficient_balance'))
            <x-alert type="error" title="{{ __('Not enough send balance') }}">
                {{ session('insufficient_balance') }}
                <div class="mt-3">
                    <x-button :href="route('balance-requests.create')" size="sm">{{ __('Request top-up') }}</x-button>
                </div>
            </x-alert>
        @endif

        <form
            method="POST"
            action="{{ route('transfers.store') }}"
            x-data="{
                /* Two live fields. Whichever one the agent types in is the
                   real one; the other fills itself from the rates. `mode`
                   just records which, so the server recalculates the same
                   leg the agent meant. */
                mode: '{{ old('mode', 'receive') }}',
                payoutAmount: '{{ old('payout_amount') }}',
                payoutCurrency: '{{ old('payout_currency', 'USD') }}',
                payAmount: '{{ old('pay_amount') }}',
                payCurrency: '{{ old('currency', $payCurrency) }}',
                quote: null,
                quoteError: null,
                loading: false,
                confirming: false,
                sourceAmount() {
                    return this.mode === 'pay' ? this.payAmount : this.payoutAmount;
                },
                typedIn(mode) {
                    this.mode = mode;
                    this.confirming = false;
                    this.fetchQuote();
                },
                async fetchQuote() {
                    this.quote = null; this.quoteError = null;
                    const amount = this.sourceAmount();
                    if (!amount || Number(amount) <= 0) {
                        if (this.mode === 'pay') { this.payoutAmount = ''; } else { this.payAmount = ''; }
                        return;
                    }
                    this.loading = true;
                    try {
                        const res = await fetch('{{ route('transfers.quote') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({
                                mode: this.mode,
                                currency: this.payCurrency,
                                payout_currency: this.payoutCurrency,
                                amount: amount,
                            }),
                        });
                        const data = await res.json();
                        if (res.ok) {
                            this.quote = data;
                            /* Fill the other side. Setting x-model fires no
                               input event, so this never loops. */
                            if (this.mode === 'pay') {
                                this.payoutAmount = (data.payout_original_cents / 100).toFixed(2);
                            } else {
                                this.payAmount = (data.collect_original_cents / 100).toFixed(2);
                            }
                        } else {
                            this.quoteError = data.message || '{{ __('Could not calculate a quote.') }}';
                        }
                    } catch (e) {
                        this.quoteError = '{{ __('Could not reach the server.') }}';
                    } finally {
                        this.loading = false;
                    }
                },
                money(cents) {
                    return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
                inCurrency(cents, currency) {
                    return (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
                },
            }"
            x-init="fetchQuote()"
            class="space-y-6"
        >
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <input type="hidden" name="mode" x-model="mode">
            @if (session('duplicate_warning'))
                <input type="hidden" name="confirm_duplicate" value="1">
            @endif

            <div class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ __('Tracking number') }}</p>
                    <p class="mt-1 font-mono text-2xl font-bold tracking-wider text-brand-900">{{ $trackingNumber }}</p>
                </div>
                <p class="hidden sm:block text-xs text-brand-700 text-right">{{ __('Give this number to the customer.') }}</p>
            </div>

            {{-- Sender: pick a saved customer to fill the boxes, or type a new one. --}}
            <x-card :title="__('Sender')" :subtitle="__('The person handing over the money.')">
                <fieldset
                    class="space-y-5"
                    x-data="{
                        q: '',
                        open: false,
                        loading: false,
                        results: [],
                        picked: '',
                        receiverFilled: false,
                        async search() {
                            this.picked = '';
                            if (this.q.trim().length < 2) { this.results = []; this.open = false; return; }
                            this.loading = true; this.open = true;
                            try {
                                const res = await fetch('{{ route('customers.search') }}?q=' + encodeURIComponent(this.q.trim()), {
                                    headers: { 'Accept': 'application/json' },
                                });
                                this.results = res.ok ? await res.json() : [];
                            } catch (e) { this.results = []; }
                            this.loading = false;
                        },
                        fill(name, value) {
                            const el = document.querySelector('[name=' + name + ']');
                            if (el) { el.value = value || ''; el.dispatchEvent(new Event('input', { bubbles: true })); }
                        },
                        pick(c) {
                            this.fill('sender_name', c.name);
                            this.fill('sender_phone', c.phone_display);
                            this.fill('sender_country', c.country);
                            this.fill('sender_city', c.city);
                            this.fill('sender_province', c.province);
                            // Same receiver as last time, when there was one.
                            if (c.receiver) {
                                for (const field of ['name', 'phone', 'country', 'city', 'province']) {
                                    this.fill('receiver_' + field, c.receiver['receiver_' + field]);
                                }
                                this.fill('receiver_account_type_id', c.receiver.receiver_account_type_id);
                                this.fill('receiver_account_number', c.receiver.receiver_account_number);
                                this.receiverFilled = true;
                            }
                            this.picked = c.name + ' — ' + c.phone_display;
                            this.q = ''; this.results = []; this.open = false;
                        },
                    }"
                >
                    <legend class="sr-only">{{ __('Sender') }}</legend>

                    <div class="relative" x-on:click.outside="open = false">
                        <label for="customer_search" class="block text-sm font-medium text-slate-700 mb-1.5">{{ __('Find an existing customer') }}</label>
                        <input
                            id="customer_search" type="search" autocomplete="off"
                            x-model="q" x-on:input.debounce.300ms="search()" x-on:focus="open = results.length > 0"
                            placeholder="{{ __('Type a name or phone number') }}"
                            class="block w-full rounded-lg border border-slate-300 py-3 text-base sm:py-2.5 sm:text-sm pl-3.5 pr-3.5 shadow-sm focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-100"
                        />
                        <div x-show="open" x-cloak class="absolute z-20 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg max-h-72 overflow-auto">
                            <template x-for="c in results" :key="c.id">
                                <button type="button" x-on:click="pick(c)" class="block w-full text-left px-4 py-3 hover:bg-brand-50 border-b border-slate-100 last:border-0">
                                    <span class="block text-sm font-medium text-slate-900" x-text="c.name"></span>
                                    <span class="block text-xs text-slate-500" x-text="c.phone_display + (c.location ? ' · ' + c.location : '')"></span>
                                </button>
                            </template>
                            <p x-show="!loading && results.length === 0" class="px-4 py-3 text-sm text-slate-500">{{ __('No saved customer matches — fill in the details below to add a new one.') }}</p>
                        </div>
                        <p x-show="picked" x-cloak class="mt-1.5 text-xs text-emerald-700" x-text="'{{ __('Using saved customer') }}: ' + picked"></p>
                        <p x-show="picked && receiverFilled" x-cloak class="mt-1 text-xs text-emerald-700">{{ __('Receiver filled in from their last transfer — check it before sending.') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <x-input name="sender_name" label="{{ __('Name') }}" :value="$prefill?->name" autofocus />
                        <x-input name="sender_phone" label="{{ __('Phone') }}" :value="$prefill?->phone" />
                        <x-searchable-select name="sender_country" label="{{ __('Country') }}" :options="$countries" :value="$prefill?->country ?? $senderDefaults['country']" />
                        <x-input name="sender_city" label="{{ __('City') }}" :value="$prefill?->city ?? $senderDefaults['city']" />
                        <x-input name="sender_province" label="{{ __('Province / region') }}" :value="$prefill?->province ?? $senderDefaults['province']" hint="{{ __('Optional') }}" />
                    </div>
                </fieldset>
            </x-card>

            <x-card :title="__('Receiver')" :subtitle="__('The person collecting the money.')">
                <fieldset class="space-y-5">
                    <legend class="sr-only">{{ __('Receiver') }}</legend>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <x-input name="receiver_name" :value="$prefillReceiver['receiver_name'] ?? null" label="{{ __('Name') }}" />
                        <x-input name="receiver_phone" :value="$prefillReceiver['receiver_phone'] ?? null" label="{{ __('Phone') }}" />
                        <x-searchable-select name="receiver_country" label="{{ __('Country') }}" :options="$countries" :value="$prefillReceiver['receiver_country'] ?? 'Somalia'" />
                        <x-input name="receiver_city" :value="$prefillReceiver['receiver_city'] ?? null" label="{{ __('City') }}" />
                        <x-input name="receiver_province" :value="$prefillReceiver['receiver_province'] ?? null" label="{{ __('Province / region') }}" hint="{{ __('Optional') }}" />
                    </div>
                </fieldset>
            </x-card>

            <x-card :title="__('Amount')">
                @if ($onlyBranchId)
                    <input type="hidden" name="branch_id" value="{{ $onlyBranchId }}">
                @else
                    <div class="mb-5">
                        <x-select name="branch_id" label="{{ __('Destination branch') }}" :options="$branches" placeholder="{{ __('Select a branch') }}" />
                    </div>
                @endif

                {{-- Fill in whichever one the customer named; the other follows. --}}
                <div class="space-y-4">
                    <x-transfers.amount-field
                        name="payout_amount"
                        currency-name="payout_currency"
                        label="{{ __('Receiver gets') }}"
                        :currencies="$currencies"
                        amount-model="payoutAmount"
                        currency-model="payoutCurrency"
                        typed-mode="receive"
                    />

                    <div class="flex items-center gap-3 text-slate-300" aria-hidden="true">
                        <div class="h-px flex-1 bg-slate-200"></div>
                        <x-icon name="arrow-right-left" class="w-4 h-4 rotate-90" />
                        <div class="h-px flex-1 bg-slate-200"></div>
                    </div>

                    <x-transfers.amount-field
                        name="pay_amount"
                        currency-name="currency"
                        label="{{ __('Customer pays') }}"
                        :currencies="$currencies"
                        amount-model="payAmount"
                        currency-model="payCurrency"
                        typed-mode="pay"
                    />
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    {{ __('Type in either box — the other one fills itself from today\'s rate.') }}
                </p>

                <div class="mt-5" x-show="loading" x-cloak>
                    <p class="text-sm text-slate-500">{{ __('Calculating…') }}</p>
                </div>

                <template x-if="quoteError">
                    <div class="mt-5">
                        <x-alert type="error"><span x-text="quoteError"></span></x-alert>
                    </div>
                </template>

                <template x-if="quote && !loading">
                    <div class="mt-5 rounded-lg border border-slate-200 divide-y divide-slate-100">
                        <div class="flex justify-between px-4 py-3" x-show="payoutCurrency !== 'USD'">
                            <span class="text-sm text-slate-500">{{ __('Paid out in Somalia') }}</span>
                            <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.amount_cents)"></span>
                        </div>
                        <div class="flex justify-between px-4 py-3">
                            <span class="text-sm text-slate-500">{{ __('Fee') }}</span>
                            <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.fee_cents)"></span>
                        </div>
                        <div class="flex justify-between px-4 py-3" x-show="payCurrency !== 'USD'">
                            <span class="text-sm text-slate-500">{{ __('Rate used') }}</span>
                            <span class="text-sm font-medium text-slate-900 font-mono">
                                1 <span x-text="payCurrency"></span> =
                                <span x-text="'$' + Number(quote.customer_rate).toFixed(4)"></span>
                            </span>
                        </div>
                        <div class="flex justify-between px-4 py-3">
                            <span class="text-sm text-slate-500">{{ __('Taken from your send balance') }}</span>
                            <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.total_debit_cents)"></span>
                        </div>
                    </div>
                </template>
            </x-card>

            <x-card :title="__('Receiver prefers')" :subtitle="__('Optional — helps the payout agent.')">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="receiver_account_type_id" :value="$prefillReceiver['receiver_account_type_id'] ?? null" label="{{ __('Account type') }}" :options="$accountTypes" placeholder="{{ __('Any') }}" />
                    <x-input name="receiver_account_number" :value="$prefillReceiver['receiver_account_number'] ?? null" label="{{ __('Account number') }}" />
                </div>

                <div class="mt-5 space-y-3">
                    <x-checkbox name="is_uncollected" label="{{ __('Customer has not paid yet (track as uncollected)') }}" />
                </div>

                <div class="mt-5">
                    <x-textarea name="note" label="{{ __('Note') }}" />
                </div>
            </x-card>

            {{-- Confirmation step: both legs are shown large before the final submit. --}}
            <x-card>
                <template x-if="quote && !confirming">
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                        <x-button :href="route('transfers.index')" variant="ghost">{{ __('Back') }}</x-button>
                        <x-button type="button" x-on:click="confirming = true">{{ __('Review') }}</x-button>
                    </div>
                </template>

                <template x-if="!quote">
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                        <x-button :href="route('transfers.index')" variant="ghost">{{ __('Back') }}</x-button>
                        <x-button type="button" disabled>{{ __('Review') }}</x-button>
                    </div>
                </template>

                <template x-if="quote && confirming">
                    <div class="space-y-5">
                        <div class="text-center py-4">
                            <p class="text-sm text-slate-500">{{ __('Collect from the customer') }}</p>
                            <p class="text-4xl font-bold text-slate-900 mt-1 font-mono"
                               x-text="inCurrency(quote.collect_original_cents, payCurrency)"></p>
                            <p class="text-sm text-slate-500 mt-2">
                                {{ __('Receiver gets') }}
                                <span class="font-medium text-slate-700" x-text="money(quote.amount_cents)"></span>
                            </p>
                        </div>
                        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                            <x-button type="button" variant="ghost" x-on:click="confirming = false">{{ __('Edit') }}</x-button>
                            <x-button type="submit" size="lg">{{ __('Send transfer') }}</x-button>
                        </div>
                    </div>
                </template>
            </x-card>
        </form>
    </div>
</x-layouts.app>
