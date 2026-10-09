<x-layouts.app :title="__('Expenses')">
    @include('partials.expenses-tabs', ['active' => 'expenses'])

    <x-card :padding="false">
        <x-slot:actions>
            @can('create', \App\Models\Expense::class)
                <x-button :href="route('expenses.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('Add expense') }}
                </x-button>
            @endcan
        </x-slot:actions>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                <div class="lg:col-span-2">
                    <x-input name="search" placeholder="{{ __('Search payee, reference or note') }}" :value="request('search')" />
                </div>
                <x-select name="type" :options="$types" placeholder="{{ __('All types') }}" :value="request('type')" />
                <x-input name="from" type="date" :value="request('from')" />
                <x-input name="to" type="date" :value="request('to')" />
                <x-select name="status" :options="$statuses" placeholder="{{ __('All statuses') }}" :value="request('status')" />
                @if ($branches->isNotEmpty())
                    <x-select name="branch_id" :options="$branches" placeholder="{{ __('All branches') }}" :value="request('branch_id')" />
                @endif
                <div class="flex gap-2">
                    <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                    @if (request()->hasAny(['search', 'type', 'status', 'from', 'to', 'branch_id']))
                        <x-button :href="route('expenses.index')" variant="ghost">{{ __('Clear') }}</x-button>
                    @endif
                </div>
            </form>
        </div>

        @if ($expenses->isEmpty())
            <x-empty-state icon="wallet" title="{{ __('No expenses found') }}" description="{{ __('Record payroll, rent, bills and running costs here.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
                    <th class="px-4 py-3">{{ __('Payee / note') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </x-slot:head>

                @foreach ($expenses as $expense)
                    <tr>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Date') }}">
                            <a href="{{ route('expenses.show', $expense) }}" class="font-medium text-slate-900 hover:text-brand-600">{{ $expense->expense_date->format('Y-m-d') }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-700" data-label="{{ __('Type') }}">{{ $expense->type->name }}</td>
                        <td class="px-4 py-3 text-slate-600" data-label="{{ __('Payee / note') }}">
                            {{ $expense->payee ?: '—' }}
                            @if ($expense->description)
                                <div class="text-xs text-slate-500 truncate max-w-xs">{{ $expense->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Branch') }}">{{ $expense->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}">
                            <span class="{{ $expense->isVoided() ? 'line-through text-slate-400' : '' }}"><x-money :cents="$expense->amount_cents" /></span>
                            @if ($expense->original_currency !== 'USD')
                                <div class="text-xs text-slate-500">{{ number_format($expense->original_amount_cents / 100, 2) }} {{ $expense->original_currency }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$expense->status->color()">{{ $expense->status->label() }}</x-badge>
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5 py-3 border-t border-slate-100 flex justify-end text-sm">
                <span class="text-slate-500 mr-2">{{ __('Total paid (filtered)') }}</span>
                <span class="font-mono font-semibold text-slate-900"><x-money :cents="$total" /></span>
            </div>

            <div class="px-5">
                <x-pagination :paginator="$expenses" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
