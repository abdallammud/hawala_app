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
                mode: '{{ old('mode', 'pay') }}',
                currency: '{{ old('currency', 'USD') }}',
                amount: '{{ old('amount') }}',
                collect: '{{ old('collect') }}',
                quote: null,
                quoteError: null,
                loading: false,
                confirming: false,
                setMode(mode) {
                    if (this.mode === mode) return;
                    this.mode = mode;
                    this.amount = '';
                    this.quote = null;
                    this.confirming = false;
                },
                async fetchQuote() {
                    this.quote = null; this.quoteError = null;
                    if (!this.amount || Number(this.amount) <= 0) return;
                    this.loading = true;
                    try {
                        const res = await fetch('{{ route('transfers.quote') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ mode: this.mode, currency: this.currency, amount: this.amount }),
                        });
                        const data = await res.json();
                        if (res.ok) {
                            this.quote = data;
                            this.collect = (data.collect_min_original_cents / 100).toFixed(2);
                        } else {
                            this.quoteError = data.message || '{{ __('Could not calculate a quote.') }}';
                        }
                    } catch (e) {
                        this.quoteError = '{{ __('Could not reach the server.') }}';
                    } finally {
                        this.loading = false;
                    }
                },
                /* What the agent will really collect: the computed amount, or
                   their own rounded-up figure when they are allowed one. */
                collectCents() {
                    if (!this.quote) return 0;
                    if (!this.quote.can_round_up) return this.quote.collect_min_original_cents;
                    const typed = Math.round(Number(this.collect) * 100);
                    return Number.isFinite(typed) && typed > 0 ? typed : this.quote.collect_min_original_cents;
                },
                extraCents() {
                    return this.quote ? this.collectCents() - this.quote.collect_min_original_cents : 0;
                },
                money(cents) {
                    return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
                inCurrency(cents) {
                    return (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + this.currency;
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

            <x-card :title="__('Customer')">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-input name="sender_name" label="{{ __('Sender name') }}" autofocus />
                    <x-input name="sender_phone" label="{{ __('Sender phone') }}" />
                    <x-input name="receiver_name" label="{{ __('Receiver name') }}" />
                    <x-input name="receiver_phone" label="{{ __('Receiver phone') }}" />
                </div>
            </x-card>

            <x-card :title="__('Amount')">
                {{-- Which side the customer named. Everything else follows from it. --}}
                <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 rounded-lg mb-5">
                    @foreach (\App\Enums\QuoteMode::cases() as $case)
                        <button
                            type="button"
                            x-on:click="setMode('{{ $case->value }}'); fetchQuote()"
                            class="rounded-md px-3 py-2.5 text-sm font-medium transition"
                            :class="mode === '{{ $case->value }}' ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        >{{ $case->label() }}</button>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    @if ($onlyBranchId)
                        <input type="hidden" name="branch_id" value="{{ $onlyBranchId }}">
                    @else
                        <x-select name="branch_id" label="{{ __('Destination branch') }}" :options="$branches" placeholder="{{ __('Select a branch') }}" />
                    @endif

                    <x-select name="currency" label="{{ __('Currency the customer pays in') }}" :options="$currencies" x-model="currency" x-on:change="fetchQuote" />

                    {{-- One field, two meanings — the label says which. --}}
                    <div>
                        <label for="amount" class="block text-sm font-medium text-slate-700 mb-1.5">
                            <span x-show="mode === 'pay'">{{ __('Customer pays') }}</span>
                            <span x-show="mode === 'receive'" x-cloak>{{ __('Receiver gets (USD)') }}</span>
                        </label>
                        <input
                            id="amount" name="amount" type="number" min="0.01" step="0.01"
                            x-model="amount"
                            x-on:input.debounce.400ms="fetchQuote"
                            value="{{ old('amount') }}"
                            class="block w-full rounded-lg border py-3 text-base sm:py-2.5 sm:text-sm shadow-sm transition
                                focus:outline-none focus:ring-2 px-3.5
                                {{ $errors->has('amount')
                                    ? 'border-red-400 text-red-900 focus:border-red-500 focus:ring-red-200'
                                    : 'border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-brand-100' }}"
                        />
                        @error('amount')
                            <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-xs text-slate-500">
                                <span x-show="mode === 'pay'">{{ __('The cash in their hand, fee included.') }}</span>
                                <span x-show="mode === 'receive'" x-cloak>{{ __('What the receiver is paid in Somalia.') }}</span>
                            </p>
                        @enderror
                    </div>
                </div>

                <div class="mt-5" x-show="loading" x-cloak>
                    <p class="text-sm text-slate-500">{{ __('Calculating…') }}</p>
                </div>

                <template x-if="quoteError">
                    <div class="mt-5">
                        <x-alert type="error"><span x-text="quoteError"></span></x-alert>
                    </div>
                </template>

                <template x-if="quote && !loading">
                    <div class="mt-5 space-y-4">
                        <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
                            <div class="flex justify-between px-4 py-3">
                                <span class="text-sm text-slate-500">{{ __('Receiver gets') }}</span>
                                <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.amount_cents)"></span>
                            </div>
                            <div class="flex justify-between px-4 py-3">
                                <span class="text-sm text-slate-500">{{ __('Fee') }}</span>
                                <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.fee_cents)"></span>
                            </div>
                            <div class="flex justify-between px-4 py-3" x-show="currency !== 'USD'">
                                <span class="text-sm text-slate-500">{{ __('Rate used') }}</span>
                                <span class="text-sm font-medium text-slate-900 font-mono">
                                    1 <span x-text="currency"></span> =
                                    <span x-text="'$' + Number(quote.customer_rate).toFixed(4)"></span>
                                </span>
                            </div>
                            <div class="flex justify-between px-4 py-3 bg-brand-50">
                                <span class="text-sm font-semibold text-brand-900">{{ __('Collect from customer') }}</span>
                                <span class="text-sm font-semibold text-brand-900 font-mono" x-text="inCurrency(collectCents())"></span>
                            </div>
                            <div class="flex justify-between px-4 py-3">
                                <span class="text-sm text-slate-500">{{ __('Taken from your send balance') }}</span>
                                <span class="text-sm font-medium text-slate-900 font-mono" x-text="money(quote.total_debit_cents)"></span>
                            </div>
                        </div>

                        {{-- Rounding the collected amount up is a price change for
                             a real customer, so only agents with the permission
                             see it, and the server re-checks the ceiling. --}}
                        <div x-show="quote.can_round_up" x-cloak>
                            <label for="collect" class="block text-sm font-medium text-slate-700 mb-1.5">
                                {{ __('Collect instead') }}
                            </label>
                            <input
                                id="collect" name="collect" type="number" step="0.01"
                                x-model="collect"
                                :disabled="! quote.can_round_up"
                                :min="(quote.collect_min_original_cents / 100).toFixed(2)"
                                :max="(quote.max_collect_original_cents / 100).toFixed(2)"
                                class="block w-full rounded-lg border border-slate-300 py-3 text-base sm:py-2.5 sm:text-sm shadow-sm px-3.5
                                    text-slate-900 transition focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-100"
                            />
                            <p class="mt-1.5 text-xs text-slate-500" x-show="extraCents() <= 0">
                                {{ __('You may round this up. The receiver still gets the amount above.') }}
                            </p>
                            <p class="mt-1.5 text-xs text-brand-700" x-show="extraCents() > 0" x-cloak>
                                <span x-text="inCurrency(extraCents())"></span>
                                {{ __('above the calculated amount — kept by the company.') }}
                            </p>
                        </div>
                    </div>
                </template>
                @error('collect')
                    <p class="mt-3 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </x-card>

            <x-card :title="__('Receiver prefers')" :subtitle="__('Optional — helps the payout agent.')">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-select name="receiver_account_type_id" label="{{ __('Account type') }}" :options="$accountTypes" placeholder="{{ __('Any') }}" />
                    <x-input name="receiver_account_number" label="{{ __('Account number') }}" />
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
                            <p class="text-4xl font-bold text-slate-900 mt-1 font-mono" x-text="inCurrency(collectCents())"></p>
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
