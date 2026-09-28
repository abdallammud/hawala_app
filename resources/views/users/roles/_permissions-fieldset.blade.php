{{-- $groupedPermissions: Collection<string, Collection<Permission>>, $selected: array<string> --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    @foreach ($groupedPermissions as $module => $permissions)
        <div
            x-data="{
                items: {{ Illuminate\Support\Js::from($permissions->pluck('name')) }},
                checked: {{ Illuminate\Support\Js::from(array_values(array_intersect($permissions->pluck('name')->all(), $selected))) }},
                get all() { return this.items.length > 0 && this.items.every(i => this.checked.includes(i)); },
                toggleAll() { this.checked = this.all ? [] : [...this.items]; },
            }"
            class="rounded-lg border border-slate-200 p-4"
        >
            <label class="flex items-center gap-2.5 pb-2.5 mb-2.5 border-b border-slate-100 cursor-pointer">
                <input type="checkbox" x-model="all" x-on:change="toggleAll" class="h-4.5 w-4.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                <span class="text-sm font-semibold text-slate-900 capitalize">{{ str_replace('_', ' ', $module) }}</span>
            </label>

            <div class="space-y-2">
                @foreach ($permissions as $permission)
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input
                            type="checkbox"
                            name="permissions[]"
                            value="{{ $permission->name }}"
                            x-model="checked"
                            class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        <span class="text-sm text-slate-600">{{ str($permission->name)->after('.')->replace('_', ' ') }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
