<?php

namespace App\Http\Requests\Expenses;

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
        return [
            // An inactive type may stay on an old expense, but can't be newly picked.
            'expense_type_id' => ['required', Rule::exists('expense_types', 'id')->where(
                fn ($q) => $q->where('is_active', true)->orWhere('id', $this->route('expense')->expense_type_id)
            )],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payee' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
