<?php

namespace App\Exceptions\Rates;

use RuntimeException;

class RateUnavailableException extends RuntimeException
{
    public static function forCurrency(string $code): self
    {
        return new self(__('Exchange rate for :code is out of date. Ask admin to set a manual rate.', ['code' => $code]));
    }
}
