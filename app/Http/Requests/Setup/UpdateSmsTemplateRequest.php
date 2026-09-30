<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.sms');
    }

    public function rules(): array
    {
        return [
            // 640 characters is four SMS segments — long enough for any of
            // these messages, short enough to stay sane to pay for.
            'body' => ['required', 'string', 'max:640'],
            'to_sender' => ['nullable', 'boolean'],
            'to_receiver' => ['nullable', 'boolean'],
            'to_sending_agent' => ['nullable', 'boolean'],
            'to_payout_agent' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
