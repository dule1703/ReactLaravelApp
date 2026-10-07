<?php

use App\Support\BackupSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: keeps the activity log within ACTIVITY_LOG_RETENTION_DAYS (needs the cron from 0.10).
Schedule::command('activitylog:prune')->daily();

// Backups (7.2): the database every day, the uploaded files every week. Only where there is a MySQL /
// MariaDB database to dump (BACKUP_ENABLED); see App\Support\BackupSchedule.
if (config('backup.enabled')) {
    BackupSchedule::register(Schedule::getFacadeRoot());
}
