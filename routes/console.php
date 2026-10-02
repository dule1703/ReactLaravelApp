<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: keeps the activity log within ACTIVITY_LOG_RETENTION_DAYS (needs the cron from 0.10).
Schedule::command('activitylog:prune')->daily();
