<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full schedule per BUILD.md §14.
//
// Hostinger gives us one cron (`* * * * * php artisan schedule:run`), so the
// queue is drained here rather than by a long-running worker: --stop-when-empty
// exits as soon as the queue is clear and --max-time keeps each run inside the
// minute, while withoutOverlapping stops two runs colliding.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('hawala:expire-claims')->everyMinute()->withoutOverlapping();
Schedule::command('hawala:fetch-rates')->hourly();
Schedule::command('hawala:verify-ledger')->dailyAt('02:00');

// Prune first, then take tonight's copy, so a full disk cannot block the
// backup. Destination disks come from BACKUP_DISKS (see DEPLOY.md).
Schedule::command('backup:clean')->dailyAt('03:00');
Schedule::command('backup:run')->dailyAt('03:10');

// Two years of audit history is kept (BUILD.md §14).
Schedule::command('activitylog:clean --days=730')->monthly();
