<x-layouts.app :title="__('Cities')">
    <a href="{{ route('setup.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 mb-2">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        {{ __('Setup') }}
    </a>

    <x-card :padding="false">
        <x-slot:actions>
            <x-button :href="route('setup.cities.create', ['country' => request('country')])" size="sm">
                <x-icon name="plus-circle" class="w-4 h-4" />
                {{ __('New city') }}
            </x-button>
        </x-slot:actions>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="sm:w-64">
                    <x-select name="country" :options="array_combine($countries, $countries)" placeholder="{{ __('All countries') }}" :value="request('country')" />
                </div>
                <div class="flex-1">
                    <x-input name="q" placeholder="{{ __('Search city') }}" :value="request('q')" />
                </div>
                <x-button type="submit" variant="secondary">{{ __('Search') }}</x-button>
                @if (request()->filled('country') || request()->filled('q'))
                    <x-button :href="route('setup.cities.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($cities->isEmpty())
            <x-empty-state icon="building-library" title="{{ __('No cities found') }}" description="{{ __('Add the cities agents can pick for this country.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Country') }}</th>
                    <th class="px-4 py-3">{{ __('City') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($cities as $city)
                    <tr>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Country') }}">{{ $city->country }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('City') }}">{{ $city->name }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            <x-badge :color="$city->is_active ? 'green' : 'slate'">
                                {{ $city->is_active ? __('Active') : __('Inactive') }}
                            </x-badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3" data-label="">
                            <a href="{{ route('setup.cities.edit', $city) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('setup.cities.toggle', $city) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm font-medium {{ $city->is_active ? 'text-red-600 hover:text-red-700' : 'text-slate-600 hover:text-slate-700' }}">
                                    {{ $city->is_active ? __('Deactivate') : __('Activate') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <x-pagination :paginator="$cities" />
        @endif
    </x-card>
</x-layouts.app>
