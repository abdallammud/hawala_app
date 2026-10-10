<?php

namespace App\Support;

use Spatie\Activitylog\Models\Activity;

/**
 * Turns raw logged values into what a person would read. The log keeps the
 * stored form (basis points / cents); only the audit page translates it.
 */
class AuditValue
{
    /** @param 'old'|'attributes' $side which half of the log entry the value came from */
    public static function format(Activity $activity, string $field, mixed $value, string $side): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_scalar($value)) {
            return (string) json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (str_ends_with($field, '_cents') && is_numeric($value)) {
            return Money::format((int) $value);
        }

        if (preg_match('/^(send|payout)_commission_value$/', $field, $m) && is_numeric($value)) {
            $typeField = $m[1].'_commission_type';
            // The type may not be in this entry if it did not change; the
            // model records the user's types alongside every entry.
            $type = $activity->properties[$side][$typeField]
                ?? $activity->properties['context'][$typeField]
                ?? null;

            return CommissionInput::describe($type, (int) $value);
        }

        return (string) $value;
    }
}
