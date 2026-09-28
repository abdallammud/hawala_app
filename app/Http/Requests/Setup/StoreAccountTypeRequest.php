<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.account_types');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('account_types', 'name')->ignore($this->route('accountType'))],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
