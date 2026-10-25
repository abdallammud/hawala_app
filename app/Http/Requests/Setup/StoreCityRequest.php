<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.cities');
    }

    public function rules(): array
    {
        return [
            'country' => ['required', 'string', Rule::in(config('hawala.countries'))],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('cities', 'name')->where('country', $this->input('country'))->ignore($this->route('city')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('This city already exists for that country.'),
        ];
    }
}
