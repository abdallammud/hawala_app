<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the `settings` table, backed by config/hawala.php defaults.
 * Keys are dot notation, e.g. Settings::get('fees.mode').
 */
class Settings
{
    private const CACHE_KEY = 'hawala:settings';

    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $defaults = config('hawala');
            $stored = Setting::query()->get()->pluck('value', 'key');

            foreach ($stored as $key => $value) {
                Arr::set($defaults, $key, $value);
            }

            return $defaults;
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(self::all(), $key, $default);
    }

    public static function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy]
        );

        self::clearCache();
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
