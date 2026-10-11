<?php

namespace App\Http\Requests\Expenses;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('expense'));
    }

    public function rules(): array
    {
        $expense = $this->route('expense');

        return [
            // An inactive type or account may stay on an old expense, but can't be newly picked.
            'expense_type_id' => ['required', Rule::exists('expense_types', 'id')->where(
                fn ($q) => $q->where('is_active', true)->orWhere('id', $expense->expense_type_id)
            )],
            'company_account_id' => ['required', Rule::exists('company_accounts', 'id')->where(
                fn ($q) => $q->where('is_active', true)->orWhere('id', $expense->company_account_id)
            )],
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payee' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function amountCents(): int
    {
        return Money::parseCents($this->string('amount'));
    }
}
