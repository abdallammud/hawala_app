<?php

namespace App\Services\Rates;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fallback rate provider, used when FrankfurterProvider fails.
 * https://www.exchangerate-api.com/docs/free — units of each currency per 1
 * USD; inverted the same way as FrankfurterProvider.
 */
class OpenErApiProvider implements RateProvider
{
    public function fetch(array $currencyCodes): array
    {
        $response = Http::timeout(10)->get('https://open.er-api.com/v6/latest/USD');

        if ($response->failed() || $response->json('result') !== 'success') {
            throw new RuntimeException('open.er-api.com request failed.');
        }

        $rates = $response->json('rates');

        if (! is_array($rates) || empty($rates)) {
            throw new RuntimeException('open.er-api.com response had no rates.');
        }

        $result = [];

        foreach ($currencyCodes as $code) {
            if (! isset($rates[$code]) || (float) $rates[$code] <= 0) {
                continue;
            }

            $result[$code] = BigDecimal::one()->dividedBy((string) $rates[$code], 8, RoundingMode::HALF_UP);
        }

        return $result;
    }

    public function name(): string
    {
        return 'open-er-api';
    }
}
