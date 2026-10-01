<?php

namespace App\Http\Requests\Transfers;

use App\Enums\QuoteMode;
use App\Models\Transfer;
use App\Services\Rates\QuoteService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class StoreTransferRequest extends FormRequest
{
    private bool $collectResolved = false;

    private ?int $collectCents = null;

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
            'mode' => ['required', Rule::enum(QuoteMode::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'collect' => ['nullable', 'numeric', 'min:0'],
            'is_uncollected' => ['boolean'],
            'confirm_duplicate' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function mode(): QuoteMode
    {
        return QuoteMode::tryFrom($this->string('mode')->toString()) ?? QuoteMode::Pay;
    }

    /**
     * How much the agent added on top of the computed amount to collect, in
     * the customer's currency. Only Receive mode has a computed amount to
     * round up from.
     */
    public function roundUpCents(): int
    {
        $computed = $this->computedCollectCents();

        if ($computed === null || $this->mode() !== QuoteMode::Receive || $this->input('collect') === null) {
            return 0;
        }

        return max(0, Money::parseCents($this->string('collect')) - $computed);
    }

    /**
     * The round-up is charged to a real customer standing at the counter, so
     * it is checked here as well as clamped in TransferService: not below the
     * computed amount, not above the cap, not without the permission.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->mode() !== QuoteMode::Receive || $this->input('collect') === null) {
                return;
            }

            $typed = Money::parseCents($this->string('collect'));
            $computed = $this->computedCollectCents();

            if ($computed === null || $typed === $computed) {
                return;
            }

            if ($typed < $computed) {
                $validator->errors()->add('collect', __('Collect at least :amount.', [
                    'amount' => Money::format($computed, $this->string('currency')->toString()),
                ]));

                return;
            }

            if (! $this->user()->can('transfers.round_collect')) {
                $validator->errors()->add('collect', __('You may not change the amount to collect.'));

                return;
            }

            $maxBp = (int) Settings::get('currency.max_round_up_bp', 500);
            $max = $computed + intdiv($computed * $maxBp, 10000);

            if ($typed > $max) {
                $validator->errors()->add('collect', __('Collect at most :amount.', [
                    'amount' => Money::format($max, $this->string('currency')->toString()),
                ]));
            }
        }];
    }

    /**
     * The amount the maths says to collect, or null when no quote can be made
     * (a stale rate) — the controller reports that failure on its own.
     */
    private function computedCollectCents(): ?int
    {
        if ($this->collectResolved) {
            return $this->collectCents;
        }

        $this->collectResolved = true;

        try {
            $this->collectCents = app(QuoteService::class)->quote(
                $this->mode(),
                $this->string('currency')->toString(),
                Money::parseCents($this->string('amount')),
                (int) Settings::get('currency.margin_bp', 0),
            )['collect_min_original_cents'];
        } catch (Throwable) {
            $this->collectCents = null;
        }

        return $this->collectCents;
    }
}
