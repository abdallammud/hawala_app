<?php

namespace App\Http\Requests\Balances;

use App\Enums\BalanceRequestKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBalanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('balances.request');
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(BalanceRequestKind::requestableBy($this->user())))],
            'user_id' => ['nullable', 'exists:users,id'],
            'company_account_id' => ['nullable', 'exists:company_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
