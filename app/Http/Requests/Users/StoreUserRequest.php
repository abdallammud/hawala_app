<?php

namespace App\Http\Requests\Users;

use App\Enums\CommissionType;
use App\Enums\UserType;
use App\Models\User;
use App\Rules\CommissionValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'string', Password::defaults()],
            'type' => ['required', Rule::enum(UserType::class)],
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'role' => ['required', 'exists:roles,name'],
            'send_commission_type' => ['nullable', Rule::enum(CommissionType::class)],
            'send_commission_value' => ['nullable', new CommissionValue('send_commission_type')],
            'payout_commission_type' => ['nullable', Rule::enum(CommissionType::class)],
            'payout_commission_value' => ['nullable', new CommissionValue('payout_commission_type')],
            'max_credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
