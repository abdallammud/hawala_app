<?php

namespace App\Http\Requests\Expenses;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'expense_type_id' => ['required', Rule::exists('expense_types', 'id')->where('is_active', true)],
            'company_account_id' => ['required', Rule::exists('company_accounts', 'id')->where('is_active', true)],
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
