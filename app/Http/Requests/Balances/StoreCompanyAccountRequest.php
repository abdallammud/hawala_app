<?php

namespace App\Http\Requests\Balances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company_accounts.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('company_accounts', 'name')->ignore($this->route('companyAccount'))],
            'location' => ['required', Rule::in(['europe', 'somalia', 'other'])],
        ];
    }
}
