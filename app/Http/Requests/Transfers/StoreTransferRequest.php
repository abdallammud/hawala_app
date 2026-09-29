<?php

namespace App\Http\Requests\Transfers;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transfer::class);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_phone' => ['required', 'string', 'max:32'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:32'],
            'receiver_account_type_id' => ['nullable', Rule::exists('account_types', 'id')->where('is_active', true)],
            'receiver_account_number' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'is_uncollected' => ['boolean'],
            'confirm_duplicate' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
