<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full schedule per BUILD.md §14. Entries are added as their commands land —
// hawala:expire-claims (Phase 5) and backup:clean/backup:run (Phase 7,
// once a remote disk is configured) are still pending.
Schedule::command('hawala:fetch-rates')->hourly();
Schedule::command('hawala:verify-ledger')->dailyAt('02:00');
Schedule::command('activitylog:clean')->monthly();
