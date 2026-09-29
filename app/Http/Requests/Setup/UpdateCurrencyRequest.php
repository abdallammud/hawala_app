<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.currencies');
    }

    public function rules(): array
    {
        return [
            'is_active' => ['boolean'],
            'manual_rate' => ['nullable', 'numeric', 'min:0.00000001'],
        ];
    }
}
