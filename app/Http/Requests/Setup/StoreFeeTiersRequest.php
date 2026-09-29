<?php

namespace App\Http\Requests\Setup;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the whole fee tier set atomically — simpler and safer than
 * per-row CRUD against BUILD.md §2's cross-row rules (first tier from 0,
 * no gaps, no overlaps, exactly one open-ended last tier).
 */
class StoreFeeTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setup.fees');
    }

    public function rules(): array
    {
        return [
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.from' => ['required', 'numeric', 'min:0'],
            'tiers.*.to' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.flat' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.cap' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $rows = collect($this->input('tiers', []))
                ->map(fn (array $row) => [
                    'from' => Money::parseCents((string) $row['from']),
                    'to' => isset($row['to']) && $row['to'] !== '' ? Money::parseCents((string) $row['to']) : null,
                ])
                ->sortBy('from')
                ->values();

            if ($rows->isEmpty()) {
                return;
            }

            if ($rows->first()['from'] !== 0) {
                $validator->errors()->add('tiers', __('The first tier must start from $0.'));

                return;
            }

            $openEnded = $rows->filter(fn ($row) => $row['to'] === null);

            if ($openEnded->count() !== 1) {
                $validator->errors()->add('tiers', __('Exactly one tier must be open-ended (no "to" amount) — the last one.'));

                return;
            }

            if ($rows->last()['to'] !== null) {
                $validator->errors()->add('tiers', __('The open-ended tier must be the last one.'));

                return;
            }

            for ($i = 0; $i < $rows->count() - 1; $i++) {
                if ($rows[$i]['to'] !== $rows[$i + 1]['from']) {
                    $validator->errors()->add('tiers', __('Tiers must not have gaps or overlaps — each tier\'s "to" must equal the next tier\'s "from".'));

                    return;
                }
            }
        });
    }
}
