<x-layouts.app :title="__('Currencies')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    <x-card :padding="false">
        <x-slot:actions>
            <form method="POST" action="{{ route('setup.currencies.fetch') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">{{ __('Fetch rates now') }}</x-button>
            </form>
        </x-slot:actions>

        <x-table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Code') }}</th>
                <th class="px-4 py-3">{{ __('Name') }}</th>
                <th class="px-4 py-3">{{ __('Latest fetched rate') }}</th>
                <th class="px-4 py-3">{{ __('Manual override') }}</th>
                <th class="px-4 py-3">{{ __('Active') }}</th>
                <th class="px-4 py-3"></th>
            </x-slot:head>

            @foreach ($currencies as $currency)
                <tr>
                    <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Code') }}">{{ $currency->code }}</td>
                    <td class="px-4 py-3 text-slate-500" data-label="{{ __('Name') }}">{{ $currency->name }}</td>
                    <td class="px-4 py-3 text-slate-500" data-label="{{ __('Latest fetched rate') }}">
                        @if ($currency->code === 'USD')
                            <span class="text-slate-400">—</span>
                        @elseif ($currency->rates->isNotEmpty())
                            {{ rtrim(rtrim($currency->rates->first()->rate_to_usd, '0'), '.') }}
                            <span class="text-xs text-slate-400">({{ $currency->rates->first()->fetched_at->diffForHumans() }})</span>
                        @else
                            <span class="text-slate-400">{{ __('never fetched') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" data-label="{{ __('Manual override') }}">
                        @if ($currency->code === 'USD')
                            <span class="text-slate-400">—</span>
                        @else
                            <form method="POST" action="{{ route('setup.currencies.update', $currency) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="is_active" value="{{ $currency->is_active ? 1 : 0 }}">
                                <input
                                    type="number" step="0.00000001" min="0" name="manual_rate"
                                    value="{{ $currency->manual_rate }}" placeholder="{{ __('none') }}"
                                    class="w-32 rounded-lg border border-slate-300 py-1.5 px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500"
                                >
                                <button type="submit" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Save') }}</button>
                            </form>
                        @endif
                    </td>
                    <td class="px-4 py-3" data-label="{{ __('Active') }}">
                        @if ($currency->code === 'USD')
                            <x-badge color="green">{{ __('Active') }}</x-badge>
                        @else
                            <form method="POST" action="{{ route('setup.currencies.update', $currency) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="manual_rate" value="{{ $currency->manual_rate }}">
                                <input type="hidden" name="is_active" value="{{ $currency->is_active ? 0 : 1 }}">
                                <button type="submit">
                                    <x-badge :color="$currency->is_active ? 'green' : 'slate'">
                                        {{ $currency->is_active ? __('Active') : __('Inactive') }}
                                    </x-badge>
                                </button>
                            </form>
                        @endif
                    </td>
                    <td class="px-4 py-3" data-label=""></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-layouts.app>
