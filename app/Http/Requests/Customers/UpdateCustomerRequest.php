<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    protected function prepareForValidation(): void
    {
        // Uniqueness is on the digits-only form, so "+46 70…" can't collide
        // with the same number typed another way.
        $this->merge(['phone_digits' => Customer::normalizePhone($this->input('phone'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'phone_digits' => ['required', 'string', 'max:32', Rule::unique('customers', 'phone')->ignore($this->route('customer'))],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_digits.required' => __('Enter a valid phone number.'),
            'phone_digits.unique' => __('Another customer already has this phone number.'),
        ];
    }
}
