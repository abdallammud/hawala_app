@props(['statement', 'closingBalance'])

<x-card :padding="false">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ __('Closing balance') }}</p>
        <x-money :cents="$closingBalance" class="text-lg font-semibold text-slate-900" />
    </div>

    @if ($statement->isEmpty())
        <x-empty-state icon="banknotes" title="{{ __('No activity yet') }}" />
    @else
        <x-table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Date') }}</th>
                <th class="px-4 py-3">{{ __('Description') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Balance') }}</th>
            </x-slot:head>

            @foreach ($statement as $row)
                <tr>
                    <td class="px-4 py-3 text-slate-500" data-label="{{ __('Date') }}">{{ $row->created_at->format('M j, Y H:i') }}</td>
                    <td class="px-4 py-3 text-slate-700" data-label="{{ __('Description') }}">
                        {{ str($row->type->value)->replace('_', ' ')->title() }}
                        @if ($row->memo)
                            <span class="text-slate-400">— {{ $row->memo }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right" data-label="{{ __('Amount') }}">
                        <x-money :cents="$row->amount_cents" colorize />
                    </td>
                    <td class="px-4 py-3 text-right font-medium" data-label="{{ __('Balance') }}">
                        <x-money :cents="$row->running_balance_cents" />
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="px-5">
            <x-pagination :paginator="$statement" />
        </div>
    @endif
</x-card>
