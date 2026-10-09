<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A commission value as typed by a person: plain number, at most 2 decimals,
 * and no more than 100 when the type is percent. Ignored when the type has
 * no value (use default / none), since those fields are not submitted.
 */
class CommissionValue implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(private readonly string $typeField) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = $this->data[$this->typeField] ?? null;

        if (! in_array($type, ['percent', 'flat'], true)) {
            return;
        }

        if (! is_scalar($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', trim((string) $value))) {
            $fail($type === 'percent'
                ? __('Enter a percentage with up to 2 decimals, like 2.5.')
                : __('Enter a dollar amount with up to 2 decimals, like 0.20.'));

            return;
        }

        if ($type === 'percent' && (float) $value > 100) {
            $fail(__('A percentage cannot be more than 100.'));
        }
    }
}
