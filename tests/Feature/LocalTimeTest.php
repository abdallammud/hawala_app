<?php

use App\Reports\ReportFilters;
use App\Support\LocalTime;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

it('shows stored UTC times in Mogadishu time', function () {
    $stored = Carbon::parse('2026-10-17 17:14:00', 'UTC');

    expect($stored->toLocal()->format('Y-m-d H:i'))->toBe('2026-10-17 20:14');
    expect($stored->format('H:i'))->toBe('17:14'); // the original is untouched
});

it('follows the timezone setting', function () {
    Settings::set('general.timezone', 'Europe/Amsterdam');

    expect(Carbon::parse('2026-10-17 17:14:00', 'UTC')->toLocal()->format('H:i'))->toBe('19:14');
});

it('falls back to Mogadishu when the setting is not a real timezone', function () {
    Settings::set('general.timezone', 'Nowhere/Land');

    expect(LocalTime::zone())->toBe('Africa/Mogadishu');
});

it('starts and ends a day at local midnight, not UTC midnight', function () {
    expect(LocalTime::dayStart('2026-10-17')->format('Y-m-d H:i'))->toBe('2026-10-16 21:00');
    expect(LocalTime::dayEnd('2026-10-17')->format('Y-m-d H:i'))->toBe('2026-10-17 20:59');
});

it('counts a transfer made at 00:30 local time as today, not yesterday', function () {
    Carbon::setTestNow('2026-10-17 21:30:00'); // 00:30 on the 18th in Mogadishu
    [$start, $end] = LocalTime::todayRange();

    expect(Carbon::parse('2026-10-17 21:10:00', 'UTC')->between($start, $end))->toBeTrue();
    expect(Carbon::parse('2026-10-17 20:30:00', 'UTC')->between($start, $end))->toBeFalse();

    Carbon::setTestNow();
});

it('turns a report date range into local days and back again', function () {
    $filters = ReportFilters::fromRequest(Request::create('/', 'GET', ['from' => '2026-10-01', 'to' => '2026-10-17']));

    expect($filters->fromDate())->toBe('2026-10-01');
    expect($filters->toDate())->toBe('2026-10-17');
    expect($filters->from->format('Y-m-d H:i'))->toBe('2026-09-30 21:00');
});
