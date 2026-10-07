<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\BackupStorage;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Daily dump of the database (scheduled in App\Support\BackupSchedule). Restoring is manual on
 * purpose: see "Backup i oporavak" in README.md.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Write a gzip-compressed dump of the database to the backup folder and keep the newest ones';

    public function handle(ActivityLogger $logger, DatabaseBackup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (BackupFailed $e) {
            // Only the reason code reaches the log: no path, no credentials, no output of mysqldump.
            $logger->log('backup.failed', description: __('Database backup failed: :reason', ['reason' => $e->label()]));
            $this->error('Database backup failed: '.$e->reason);

            return self::FAILURE;
        }

        $logger->log(
            'backup.database_created',
            subjectLabel: $result['name'],
            description: __('Database backup created: :size', ['size' => BackupStorage::humanSize($result['bytes'])]),
        );
        $this->info("Created {$result['name']} (".BackupStorage::humanSize($result['bytes']).'), deleted '.count($result['deleted']).' old.');

        return self::SUCCESS;
    }
}
