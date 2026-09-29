<x-layouts.app :title="__('Fee Tiers')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    @error('tiers')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div
        x-data="{
            tiers: {{ Illuminate\Support\Js::from($tiers->map(fn ($t) => [
                'from' => number_format($t->from_cents / 100, 2, '.', ''),
                'to' => $t->to_cents !== null ? number_format($t->to_cents / 100, 2, '.', '') : '',
                'rate' => rtrim(rtrim(number_format($t->rate_bp / 100, 4), '0'), '.'),
                'flat' => number_format($t->flat_cents / 100, 2, '.', ''),
                'cap' => $t->cap_cents !== null ? number_format($t->cap_cents / 100, 2, '.', '') : '',
            ])) }},
            addRow() { this.tiers.push({ from: '', to: '', rate: '', flat: '0', cap: '' }); },
            removeRow(i) { this.tiers.splice(i, 1); },
            previewAmount: '',
            previewWhole: null,
            previewMarginal: null,
            async runPreview() {
                if (!this.previewAmount) { this.previewWhole = null; this.previewMarginal = null; return; }
                const res = await fetch('{{ route('setup.fee-tiers.preview') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    body: JSON.stringify({ amount: this.previewAmount }),
                });
                if (res.ok) {
                    const data = await res.json();
                    this.previewWhole = data.whole;
                    this.previewMarginal = data.marginal;
                }
            },
        }"
        class="space-y-6"
    >
        <x-card :title="__('Live preview')" :subtitle="__('Type an amount to see the fee under both modes, using the tiers currently saved.')">
            <div class="max-w-xs">
                <x-input name="preview_amount" label="{{ __('Amount ($)') }}" type="number" min="0" step="0.01" prefix="$" x-model="previewAmount" x-on:input.debounce.300ms="runPreview" />
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4 max-w-md" x-show="previewWhole !== null" x-cloak>
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-xs text-slate-500">{{ __('Whole mode') }}</p>
                    <p class="text-lg font-semibold text-slate-900" x-text="previewWhole"></p>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-xs text-slate-500">{{ __('Marginal mode') }}</p>
                    <p class="text-lg font-semibold text-slate-900" x-text="previewMarginal"></p>
                </div>
            </div>
        </x-card>

        <x-card :title="__('Tiers')" :subtitle="__('First tier starts at $0, last tier is open-ended, no gaps or overlaps.')">
            <form method="POST" action="{{ route('setup.fee-tiers.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="space-y-3">
                    <template x-for="(tier, i) in tiers" :key="i">
                        <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 items-end border border-slate-200 rounded-lg p-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('From ($)') }}</label>
                                <input type="number" step="0.01" min="0" :name="`tiers[${i}][from]`" x-model="tier.from" class="block w-full rounded-lg border border-slate-300 py-2 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('To ($, blank = ∞)') }}</label>
                                <input type="number" step="0.01" min="0" :name="`tiers[${i}][to]`" x-model="tier.to" class="block w-full rounded-lg border border-slate-300 py-2 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Rate (%)') }}</label>
                                <input type="number" step="0.01" min="0" max="100" :name="`tiers[${i}][rate]`" x-model="tier.rate" class="block w-full rounded-lg border border-slate-300 py-2 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Flat ($)') }}</label>
                                <input type="number" step="0.01" min="0" :name="`tiers[${i}][flat]`" x-model="tier.flat" class="block w-full rounded-lg border border-slate-300 py-2 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">{{ __('Cap ($)') }}</label>
                                <input type="number" step="0.01" min="0" :name="`tiers[${i}][cap]`" x-model="tier.cap" class="block w-full rounded-lg border border-slate-300 py-2 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500">
                            </div>
                            <div>
                                <x-button type="button" variant="danger" size="sm" x-on:click="removeRow(i)">{{ __('Remove') }}</x-button>
                            </div>
                        </div>
                    </template>
                </div>

                <x-button type="button" variant="secondary" size="sm" x-on:click="addRow">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('Add tier') }}
                </x-button>

                <div class="flex justify-end gap-3 pt-2 border-t border-slate-100">
                    <x-button type="submit">{{ __('Save tiers') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
