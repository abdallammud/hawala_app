<?php

namespace App\Exceptions\Balances;

use App\Support\Money;
use RuntimeException;

class MaxCreditExceededException extends RuntimeException
{
    public static function forUser(int $currentCents, int $requestedCents, int $maxCents): self
    {
        return new self(__('This would bring credit owed to :total, above the limit of :max.', [
            'total' => Money::format($currentCents + $requestedCents),
            'max' => Money::format($maxCents),
        ]));
    }
}
