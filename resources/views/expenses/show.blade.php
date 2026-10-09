<x-layouts.app :title="__('Expense')">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    @error('status')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <div class="max-w-2xl space-y-6">
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $expense->type->name }}</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900 font-mono"><x-money :cents="$expense->amount_cents" /></p>
                    @if ($expense->original_currency !== 'USD')
                        <p class="text-sm text-slate-500">
                            {{ number_format($expense->original_amount_cents / 100, 2) }} {{ $expense->original_currency }}
                            @ {{ rtrim(rtrim((string) $expense->rate, '0'), '.') }}
                        </p>
                    @endif
                </div>
                <x-badge :color="$expense->status->color()">{{ $expense->status->label() }}</x-badge>
            </div>

            <dl class="mt-5 divide-y divide-slate-100 text-sm">
                @foreach ([
                    __('Date') => $expense->expense_date->format('Y-m-d'),
                    __('Paid to') => $expense->payee ?: '—',
                    __('Reference') => $expense->reference ?: '—',
                    __('Paid from') => $expense->companyAccount->name,
                    __('Branch') => $expense->branch?->name ?? '—',
                    __('Recorded by') => $expense->createdBy->name.' · '.$expense->created_at->diffForHumans(),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="text-slate-900 text-right">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($expense->description)
                <div class="mt-5 pt-5 border-t border-slate-100">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ __('Note') }}</p>
                    <p class="text-sm text-slate-700 mt-1 whitespace-pre-line">{{ $expense->description }}</p>
                </div>
            @endif

            @if ($expense->isVoided())
                <x-alert type="warning" class="mt-5">
                    {{ __('Voided by :who on :date — :reason', ['who' => $expense->voidedBy?->name, 'date' => $expense->voided_at?->format('Y-m-d'), 'reason' => $expense->void_reason]) }}
                </x-alert>
            @endif
        </x-card>

        <div class="flex flex-wrap gap-3">
            <x-button :href="route('expenses.index')" variant="ghost">{{ __('Back') }}</x-button>
            @can('update', $expense)
                <x-button :href="route('expenses.edit', $expense)" variant="secondary">{{ __('Edit') }}</x-button>
            @endcan
            @can('void', $expense)
                <x-button type="button" variant="danger" x-data x-on:click="$dispatch('open-modal', 'void-expense')">{{ __('Void expense') }}</x-button>

                <x-modal name="void-expense" title="{{ __('Void expense') }}">
                    <form method="POST" action="{{ route('expenses.void', $expense) }}" class="space-y-4">
                        @csrf
                        <x-alert type="info">{{ __('The money goes back to :account. The expense stays on record as voided.', ['account' => $expense->companyAccount->name]) }}</x-alert>
                        <x-textarea name="reason" label="{{ __('Reason') }}" />
                        <div class="flex justify-end">
                            <x-button type="submit" variant="danger">{{ __('Void expense') }}</x-button>
                        </div>
                    </form>
                </x-modal>
            @endcan
        </div>
    </div>
</x-layouts.app>
