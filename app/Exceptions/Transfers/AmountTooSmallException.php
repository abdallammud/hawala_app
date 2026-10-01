<?php

namespace App\Exceptions\Transfers;

use App\Support\Money;
use RuntimeException;

class AmountTooSmallException extends RuntimeException
{
    public static function forBudget(int $budgetCents): self
    {
        return new self(__('That is too small to send — :amount does not cover the fee.', [
            'amount' => Money::format($budgetCents),
        ]));
    }
}
