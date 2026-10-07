<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * When the backups run (7.2), on the schedule:run cron that already exists. Times are in APP_TIMEZONE;
 * activitylog:prune runs daily at 00:00, so nothing overlaps. routes/console.php registers these only
 * when config('backup.enabled') is true.
 */
class BackupSchedule
{
    public const DATABASE_AT = '02:30';

    public const FILES_AT = '03:00';

    public const FILES_DAY = 0; // Sunday

    /** Minutes the overlap lock lives: the dump times out after 600 s, so a killed process must not block tomorrow's run. */
    public const LOCK_MINUTES = 120;

    public static function register(Schedule $schedule): void
    {
        $schedule->command('backup:database')
            ->dailyAt(self::DATABASE_AT)
            ->withoutOverlapping(self::LOCK_MINUTES)
            ->onFailure(fn () => Log::error('Scheduled backup:database failed (see the activity log: backup.failed).'));

        $schedule->command('backup:files')
            ->weeklyOn(self::FILES_DAY, self::FILES_AT)
            ->withoutOverlapping(self::LOCK_MINUTES)
            ->onFailure(fn () => Log::error('Scheduled backup:files failed (see the activity log: backup.failed).'));
    }
}
