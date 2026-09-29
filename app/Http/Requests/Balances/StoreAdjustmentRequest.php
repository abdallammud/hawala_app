<?php

namespace App\Http\Requests\Balances;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustments.create');
    }

    public function rules(): array
    {
        return [
            'debit_ledger_account_id' => ['required', 'exists:ledger_accounts,id', 'different:credit_ledger_account_id'],
            'credit_ledger_account_id' => ['required', 'exists:ledger_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
