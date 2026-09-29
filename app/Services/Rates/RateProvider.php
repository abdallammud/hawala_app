<?php

namespace App\Services\Rates;

use Brick\Math\BigDecimal;

interface RateProvider
{
    /**
     * Fetch USD exchange rates for the given currency codes.
     *
     * @param  string[]  $currencyCodes
     * @return array<string, BigDecimal> code => rate_to_usd (how many USD one unit is worth)
     *
     * @throws \RuntimeException on total failure (network error, bad response, etc.)
     */
    public function fetch(array $currencyCodes): array;

    /** A short label stored on exchange_rates.source, e.g. "frankfurter". */
    public function name(): string;
}
