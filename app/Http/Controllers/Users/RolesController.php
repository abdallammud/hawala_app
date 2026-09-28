<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    /**
     * Gate::before already grants this role everything; its permission set
     * can't be meaningfully edited and it must never be renamed away from
     * the name AppServiceProvider checks for.
     */
    public const PROTECTED_ROLE = 'super-admin';

    public function index(): View
    {
        $this->authorize('setup.roles');

        $roles = Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->get();

        return view('users.roles.index', ['roles' => $roles, 'protectedRole' => self::PROTECTED_ROLE]);
    }

    public function create(): View
    {
        $this->authorize('setup.roles');

        return view('users.roles.create', ['groupedPermissions' => $this->groupedPermissions()]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::create(['name' => $request->string('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->input('permissions', []));

        return redirect()->route('roles.index')->with('status', __(':name was created.', ['name' => $role->name]));
    }

    public function edit(Role $role): View
    {
        $this->authorize('setup.roles');

        return view('users.roles.edit', [
            'role' => $role->load('permissions'),
            'groupedPermissions' => $this->groupedPermissions(),
            'isProtected' => $role->name === self::PROTECTED_ROLE,
        ]);
    }

    public function update(StoreRoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->name === self::PROTECTED_ROLE) {
            abort(403, __('The super-admin role already has full access and cannot be edited.'));
        }

        $role->update(['name' => $request->string('name')]);
        $role->syncPermissions($request->input('permissions', []));

        return redirect()->route('roles.index')->with('status', __(':name was updated.', ['name' => $role->name]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('setup.roles');

        if ($role->name === self::PROTECTED_ROLE) {
            abort(403, __('The super-admin role cannot be deleted.'));
        }

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => __('This role is assigned to at least one user and cannot be deleted.')]);
        }

        $role->delete();

        return redirect()->route('roles.index')->with('status', __('Role deleted.'));
    }

    private function groupedPermissions()
    {
        return Permission::query()->orderBy('name')->get()
            ->groupBy(fn (Permission $permission) => str($permission->name)->before('.')->toString());
    }
}
