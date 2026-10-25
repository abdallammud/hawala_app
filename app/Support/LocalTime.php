<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timestamps are stored in UTC and shown in the company timezone
 * (setting `general.timezone`, default Africa/Mogadishu).
 *
 * Views call `$date->toLocal()` (a Carbon macro registered in AppServiceProvider).
 * Anything that filters by a calendar day ("today", a report's From/To) goes
 * through here so the day starts and ends at local midnight, not UTC midnight.
 */
class LocalTime
{
    public static function zone(): string
    {
        $zone = (string) Settings::get('general.timezone', 'Africa/Mogadishu');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Africa/Mogadishu';
    }

    public static function convert(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::zone());
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** The UTC instant at which the given local calendar day starts. */
    public static function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::zone())->startOfDay()->utc();
    }

    /** The UTC instant at which the given local calendar day ends. */
    public static function dayEnd(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::zone())->endOfDay()->utc();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds of the local "today" */
    public static function todayRange(): array
    {
        $today = self::now()->toDateString();

        return [self::dayStart($today), self::dayEnd($today)];
    }

    public static function monthStart(): CarbonImmutable
    {
        return self::now()->startOfMonth()->utc();
    }
}
