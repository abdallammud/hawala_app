<x-layouts.app :title="__('Roles')">
    @include('partials.users-tabs', ['active' => 'roles'])

    @error('role')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    <x-card :padding="false">
        <x-slot:actions>
            <x-button :href="route('roles.create')" size="sm">
                <x-icon name="plus-circle" class="w-4 h-4" />
                {{ __('New role') }}
            </x-button>
        </x-slot:actions>

        <x-table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Role') }}</th>
                <th class="px-4 py-3">{{ __('Permissions') }}</th>
                <th class="px-4 py-3">{{ __('Users') }}</th>
                <th class="px-4 py-3"></th>
            </x-slot:head>

            @foreach ($roles as $role)
                <tr>
                    <td class="px-4 py-3 font-medium text-slate-900" data-label="{{ __('Role') }}">
                        {{ $role->name }}
                        @if ($role->name === $protectedRole)
                            <x-badge color="gold" class="ml-1.5">{{ __('Full access') }}</x-badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500" data-label="{{ __('Permissions') }}">
                        {{ $role->name === $protectedRole ? __('All') : $role->permissions->count() }}
                    </td>
                    <td class="px-4 py-3 text-slate-500" data-label="{{ __('Users') }}">{{ $role->users_count }}</td>
                    <td class="px-4 py-3 text-right space-x-3" data-label="">
                        @if ($role->name !== $protectedRole)
                            <a href="{{ route('roles.edit', $role) }}" class="text-brand-600 hover:text-brand-700 font-medium text-sm">{{ __('Edit') }}</a>
                            @if ($role->users_count === 0)
                                <form method="POST" action="{{ route('roles.destroy', $role) }}" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-700 font-medium text-sm">{{ __('Delete') }}</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-layouts.app>
