<?php

namespace App\Exceptions\Ledger;

use App\Models\LedgerAccount;
use App\Support\Money;
use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public static function forAccount(LedgerAccount $account, int $wouldBeCents): self
    {
        $label = $account->type->label();

        return new self(__(':label ":name" would go negative (:amount). This action was not applied.', [
            'label' => $label,
            'name' => $account->name,
            'amount' => Money::format($wouldBeCents),
        ]));
    }
}
