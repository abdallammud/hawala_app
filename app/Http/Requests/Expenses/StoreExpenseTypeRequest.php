<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses.types');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_types', 'name')->ignore($this->route('expenseType'))],
        ];
    }
}
