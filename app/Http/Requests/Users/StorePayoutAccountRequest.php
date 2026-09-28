<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StorePayoutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePayoutAccounts', User::class);
    }

    public function rules(): array
    {
        return [
            'account_type_id' => ['required', 'exists:account_types,id'],
            'number' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
