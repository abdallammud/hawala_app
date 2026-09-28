<x-layouts.app :title="__('New Role')">
    @include('partials.users-tabs', ['active' => 'roles'])

    <x-card :title="__('New role')" :subtitle="__('Name the role, then choose which permissions it grants.')">
        <form method="POST" action="{{ route('roles.store') }}" class="space-y-6">
            @csrf

            <div class="max-w-sm">
                <x-input name="name" label="{{ __('Role name') }}" hint="{{ __('Lowercase, hyphens only — e.g. branch-supervisor.') }}" autofocus />
            </div>

            <div>
                <p class="text-sm font-medium text-slate-700 mb-3">{{ __('Permissions') }}</p>
                @include('users.roles._permissions-fieldset', ['groupedPermissions' => $groupedPermissions, 'selected' => old('permissions', [])])
            </div>

            <div class="flex justify-end gap-3">
                <x-button :href="route('roles.index')" variant="ghost">{{ __('Cancel') }}</x-button>
                <x-button type="submit">{{ __('Create role') }}</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
