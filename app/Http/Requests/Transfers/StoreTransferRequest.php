<?php

namespace App\Http\Requests\Transfers;

use App\Enums\QuoteMode;
use App\Models\Transfer;
use App\Support\Money;
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
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)->where('is_payout_point', true)],
            'sender_name' => ['required', 'string', 'max:255'],
            'sender_phone' => ['required', 'string', 'max:32'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['required', 'string', 'max:32'],
            'receiver_account_type_id' => ['nullable', Rule::exists('account_types', 'id')->where('is_active', true)],
            'receiver_account_number' => ['nullable', 'string', 'max:64'],

            // Two legs, each with its own currency. `mode` says which one the
            // agent typed; the other was filled in for them and is ignored
            // here — the server recalculates it (BUILD.md §8).
            'mode' => ['required', Rule::enum(QuoteMode::class)],
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'payout_currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'pay_amount' => ['required_if:mode,pay', 'nullable', 'numeric', 'min:0.01'],
            'payout_amount' => ['required_if:mode,receive', 'nullable', 'numeric', 'min:0.01'],

            'is_uncollected' => ['boolean'],
            'confirm_duplicate' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function mode(): QuoteMode
    {
        return QuoteMode::tryFrom($this->string('mode')->toString()) ?? QuoteMode::Receive;
    }

    /** The leg the agent actually typed, in cents of its own currency. */
    public function sourceAmountCents(): int
    {
        return Money::parseCents($this->string(
            $this->mode() === QuoteMode::Pay ? 'pay_amount' : 'payout_amount'
        ));
    }

    public function messages(): array
    {
        return [
            'pay_amount.required_if' => __('Enter what the customer pays.'),
            'payout_amount.required_if' => __('Enter what the receiver gets.'),
        ];
    }
}
