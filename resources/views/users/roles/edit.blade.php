<x-layouts.app :title="$role->name">
    @include('partials.users-tabs', ['active' => 'roles'])

    <x-card :title="$role->name" :subtitle="__('Update the role name and its permissions.')">
        @if ($isProtected)
            <x-alert type="info">{{ __('The super-admin role always has full access and cannot be edited.') }}</x-alert>

            <div class="flex justify-end mt-6">
                <x-button :href="route('roles.index')" variant="ghost">{{ __('Back') }}</x-button>
            </div>
        @else
            <form method="POST" action="{{ route('roles.update', $role) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="max-w-sm">
                    <x-input name="name" label="{{ __('Role name') }}" :value="$role->name" hint="{{ __('Lowercase, hyphens only.') }}" />
                </div>

                <div>
                    <p class="text-sm font-medium text-slate-700 mb-3">{{ __('Permissions') }}</p>
                    @include('users.roles._permissions-fieldset', ['groupedPermissions' => $groupedPermissions, 'selected' => old('permissions', $role->permissions->pluck('name')->all())])
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 [&>*]:w-full sm:[&>*]:w-auto">
                    <x-button :href="route('roles.index')" variant="ghost">{{ __('Back') }}</x-button>
                    <x-button type="submit">{{ __('Save changes') }}</x-button>
                </div>
            </form>
        @endif
    </x-card>
</x-layouts.app>
