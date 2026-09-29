<?php

namespace App\Http\Requests\Balances;

use Illuminate\Foundation\Http\FormRequest;

class CompanyTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company_accounts.transfer');
    }

    public function rules(): array
    {
        return [
            'from_company_account_id' => ['required', 'exists:company_accounts,id', 'different:to_company_account_id'],
            'to_company_account_id' => ['required', 'exists:company_accounts,id'],
            'sent' => ['required', 'numeric', 'min:0.01'],
            'received' => ['required', 'numeric', 'min:0.01', 'lte:sent'],
            'reference' => ['nullable', 'string', 'max:255'],
            'transferred_at' => ['required', 'date'],
        ];
    }
}
