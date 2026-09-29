<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\RateFetchFailed;
use App\Services\Rates\FrankfurterProvider;
use App\Services\Rates\OpenErApiProvider;
use App\Services\Rates\RateProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FetchRates extends Command
{
    protected $signature = 'hawala:fetch-rates';

    protected $description = 'Fetch USD exchange rates for active currencies (primary provider, falling back on failure).';

    public function handle(FrankfurterProvider $primary, OpenErApiProvider $fallback): int
    {
        $codes = Currency::query()->where('is_active', true)->where('code', '!=', 'USD')->pluck('code')->all();

        if (empty($codes)) {
            $this->info('No active non-USD currencies to fetch.');

            return self::SUCCESS;
        }

        foreach ([$primary, $fallback] as $provider) {
            /** @var RateProvider $provider */
            try {
                $rates = $provider->fetch($codes);

                if (empty($rates)) {
                    continue;
                }

                foreach ($rates as $code => $rateToUsd) {
                    ExchangeRate::create([
                        'currency' => $code,
                        'rate_to_usd' => (string) $rateToUsd,
                        'source' => $provider->name(),
                        'fetched_at' => now(),
                    ]);
                }

                $this->info(count($rates)." rate(s) fetched via {$provider->name()}.");

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->warn("{$provider->name()} failed: {$e->getMessage()}");
            }
        }

        $this->error('All rate providers failed.');
        $this->notifyOncePerDay();

        return self::FAILURE;
    }

    private function notifyOncePerDay(): void
    {
        $cacheKey = 'hawala:rate-fetch-failure-notified:'.now()->toDateString();

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->endOfDay());

        User::role('super-admin')->each(fn (User $admin) => $admin->notify(new RateFetchFailed));
    }
}
