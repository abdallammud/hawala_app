<?php

namespace App\Services\Rates;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Primary rate provider. https://frankfurter.dev — base=USD, symbols=EUR,GBP,…
 * Response gives units of each currency per 1 USD; we invert to get
 * rate_to_usd (how many USD one unit of that currency is worth).
 */
class FrankfurterProvider implements RateProvider
{
    public function fetch(array $currencyCodes): array
    {
        $symbols = implode(',', $currencyCodes);

        $response = Http::timeout(10)->get('https://api.frankfurter.dev/v1/latest', [
            'base' => 'USD',
            'symbols' => $symbols,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Frankfurter request failed: HTTP {$response->status()}");
        }

        $rates = $response->json('rates');

        if (! is_array($rates) || empty($rates)) {
            throw new RuntimeException('Frankfurter response had no rates.');
        }

        $result = [];

        foreach ($rates as $code => $unitsPerUsd) {
            if ((float) $unitsPerUsd <= 0) {
                continue;
            }

            $result[$code] = BigDecimal::one()->dividedBy((string) $unitsPerUsd, 8, RoundingMode::HALF_UP);
        }

        return $result;
    }

    public function name(): string
    {
        return 'frankfurter';
    }
}
