@php
    $available = $report->availableFilters();
    $money = $report->moneyColumns();
    $numeric = $report->numericColumns();
@endphp

<x-layouts.app :title="$report->title()">
    <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Reports') }}
    </a>

    <x-card :padding="false">
        <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">{{ $report->title() }}</h2>
                <p class="text-xs text-slate-500 mt-0.5">{{ $report->description() }}</p>
            </div>
            <x-button
                :href="route('reports.export', array_merge([$report->key()], $filters->toQueryString()))"
                variant="secondary" size="sm">
                {{ __('Export CSV') }}
            </x-button>
        </div>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                @if (in_array('from', $available))
                    <div class="w-full sm:w-40">
                        <x-input name="from" label="{{ __('From') }}" type="date" :value="$filters->from->toDateString()" />
                    </div>
                @endif
                @if (in_array('to', $available))
                    <div class="w-full sm:w-40">
                        <x-input name="to" label="{{ __('To') }}" type="date" :value="$filters->to->toDateString()" />
                    </div>
                @endif
                @if (in_array('branch', $available))
                    <div class="w-full sm:w-44">
                        <x-select name="branch_id" label="{{ __('Branch') }}" :options="$options['branches']" placeholder="{{ __('All branches') }}" :value="$filters->branchId" />
                    </div>
                @endif
                @if (in_array('agent', $available))
                    <div class="w-full sm:w-48">
                        <x-select name="agent_id" label="{{ __('Agent') }}" :options="$options['agents']" placeholder="{{ __('All agents') }}" :value="$filters->agentId" />
                    </div>
                @endif
                @if (in_array('customer', $available))
                    <div class="w-full sm:w-64">
                        <x-select name="customer_id" label="{{ __('Customer') }}" :options="$options['customers']" placeholder="{{ __('Pick a customer') }}" :value="$filters->customerId" />
                    </div>
                @endif
                @if (in_array('status', $available))
                    <div class="w-full sm:w-44">
                        <x-select name="status" label="{{ __('Status') }}" :options="$options['statuses']" placeholder="{{ __('All statuses') }}" :value="$filters->status" />
                    </div>
                @endif
                @if (in_array('account_type', $available))
                    <div class="w-full sm:w-48">
                        <x-select name="account_type" label="{{ __('Balance') }}" :options="$options['accountTypes']" :value="$filters->accountType" />
                    </div>
                @endif
                @if (in_array('group_by', $available))
                    <div class="w-full sm:w-36">
                        <x-select name="group_by" label="{{ __('Group by') }}" :options="$options['groupBy']" :value="$filters->groupBy ?? 'day'" />
                    </div>
                @endif

                <x-button type="submit" variant="secondary">{{ __('Apply') }}</x-button>
                <x-button :href="route('reports.show', $report->key())" variant="ghost">{{ __('Reset') }}</x-button>
            </form>
        </div>

        @if ($rows->isEmpty())
            @if (in_array('customer', $available) && ! $filters->customerId)
                <x-empty-state icon="users" title="{{ __('Pick a customer') }}" description="{{ __('Choose a customer above to see their transfers.') }}" />
            @else
                <x-empty-state icon="chart-bar" title="{{ __('Nothing in this range') }}" description="{{ __('Try a wider date range or different filters.') }}" />
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            @foreach ($report->columns() as $key => $label)
                                <th class="px-4 py-3 whitespace-nowrap {{ in_array($key, $numeric) ? 'text-right' : '' }}">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($report->columns() as $key => $label)
                                    <td class="px-4 py-3 whitespace-nowrap {{ in_array($key, $numeric) ? 'text-right font-mono tabular-nums' : 'text-slate-700' }}">
                                        @if (in_array($key, $money))
                                            <x-money :cents="$row[$key] ?? 0" colorize />
                                        @else
                                            {{ $row[$key] ?? '' }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if (! empty($totals))
                        <tfoot class="bg-slate-50 font-semibold">
                            <tr>
                                @foreach ($report->columns() as $key => $label)
                                    <td class="px-4 py-3 whitespace-nowrap {{ in_array($key, $numeric) ? 'text-right font-mono tabular-nums' : 'text-slate-700' }}">
                                        @if (array_key_exists($key, $totals))
                                            @if (in_array($key, $money))
                                                <x-money :cents="$totals[$key]" colorize />
                                            @else
                                                {{ $totals[$key] }}
                                            @endif
                                        @elseif ($loop->first)
                                            {{ __('Total') }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <div class="px-5 py-3 text-xs text-slate-500 border-t border-slate-100">
                {{ trans_choice(':count row|:count rows', $rows->count(), ['count' => $rows->count()]) }}
            </div>
        @endif
    </x-card>
</x-layouts.app>
