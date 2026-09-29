<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full schedule per BUILD.md §14. backup:clean/backup:run land in Phase 7,
// once a remote disk is configured.
Schedule::command('hawala:expire-claims')->everyMinute()->withoutOverlapping();
Schedule::command('hawala:fetch-rates')->hourly();
Schedule::command('hawala:verify-ledger')->dailyAt('02:00');
Schedule::command('activitylog:clean')->monthly();
