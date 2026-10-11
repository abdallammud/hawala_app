<?php

namespace App\Http\Requests\Users;

use App\Enums\CommissionType;
use App\Enums\UserType;
use App\Rules\CommissionValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'email_notifications' => ['nullable', 'boolean'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($userId)],
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
