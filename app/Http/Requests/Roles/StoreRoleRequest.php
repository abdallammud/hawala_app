<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.roles');
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9\-]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($roleId),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => __('Use lowercase letters, numbers and hyphens only, e.g. "branch-supervisor".'),
        ];
    }
}
