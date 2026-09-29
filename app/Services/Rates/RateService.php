<?php

namespace App\Services\Rates;

use App\Exceptions\Rates\RateUnavailableException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\Settings;
use Brick\Math\BigDecimal;

class RateService
{
    /**
     * USD → 1. Manual override if set. Else the latest fetched rate if
     * younger than currency.max_rate_age_hours, else throws.
     */
    public function current(string $code): BigDecimal
    {
        if ($code === 'USD') {
            return BigDecimal::one();
        }

        $currency = Currency::query()->findOrFail($code);

        if ($currency->manual_rate !== null) {
            return BigDecimal::of((string) $currency->manual_rate);
        }

        $maxAgeHours = Settings::get('currency.max_rate_age_hours', 72);

        $latest = ExchangeRate::query()
            ->where('currency', $code)
            ->where('fetched_at', '>=', now()->subHours($maxAgeHours))
            ->orderByDesc('fetched_at')
            ->first();

        if (! $latest) {
            throw RateUnavailableException::forCurrency($code);
        }

        return BigDecimal::of((string) $latest->rate_to_usd);
    }
}
