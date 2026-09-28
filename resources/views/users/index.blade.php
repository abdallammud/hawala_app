<x-layouts.app :title="__('Users')">
    @include('partials.users-tabs', ['active' => 'users'])

    <x-card :padding="false">
        <x-slot:actions>
            @can('create', \App\Models\User::class)
                <x-button :href="route('users.create')" size="sm">
                    <x-icon name="plus-circle" class="w-4 h-4" />
                    {{ __('New user') }}
                </x-button>
            @endcan
        </x-slot:actions>

        <div class="p-5 border-b border-slate-100">
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <x-input name="search" placeholder="{{ __('Search name, email or phone') }}" :value="request('search')" />
                </div>
                <x-select name="type" :options="$types" placeholder="{{ __('All types') }}" :value="request('type')" class="sm:w-48" />
                <x-select name="status" :options="['active' => __('Active'), 'inactive' => __('Inactive')]" placeholder="{{ __('All statuses') }}" :value="request('status')" class="sm:w-40" />
                <x-button type="submit" variant="secondary">{{ __('Filter') }}</x-button>
                @if (request()->hasAny(['search', 'type', 'status']))
                    <x-button :href="route('users.index')" variant="ghost">{{ __('Clear') }}</x-button>
                @endif
            </form>
        </div>

        @if ($users->isEmpty())
            <x-empty-state icon="users" title="{{ __('No users found') }}" description="{{ __('Try a different search, or create the first user.') }}" />
        @else
            <x-table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Contact') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3">{{ __('Role') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </x-slot:head>

                @foreach ($users as $user)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Name') }}">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Contact') }}">
                            <div>{{ $user->email }}</div>
                            @if ($user->phone)
                                <div class="text-xs">{{ $user->phone }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('Type') }}">{{ $user->type->label() }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Branch') }}">{{ $user->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500" data-label="{{ __('Role') }}">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                        <td class="px-4 py-3" data-label="{{ __('Status') }}">
                            @if ($user->is_active)
                                <x-badge color="green">{{ __('Active') }}</x-badge>
                            @else
                                <x-badge color="red">{{ __('Inactive') }}</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right" data-label="">
                            @can('update', $user)
                                <a href="{{ route('users.edit', $user) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="px-5">
                <x-pagination :paginator="$users" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
