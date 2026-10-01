<?php

namespace App\Http\Requests\Transfers;

use App\Enums\QuoteMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfers.create');
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'mode' => ['nullable', Rule::enum(QuoteMode::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'round_up' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
