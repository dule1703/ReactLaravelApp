<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use App\Services\Backup\BackupFailed;
use App\Services\Backup\BackupStorage;
use App\Services\Backup\FilesBackup;
use Illuminate\Console\Command;

/**
 * Weekly archive of the uploaded images and of the private real catalog file (scheduled in
 * App\Support\BackupSchedule). shared/.env is never part of it.
 */
class BackupFiles extends Command
{
    protected $signature = 'backup:files';

    protected $description = 'Write a tar.gz of the uploaded images and the private catalog file and keep the newest ones';

    public function handle(ActivityLogger $logger, FilesBackup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (BackupFailed $e) {
            $logger->log('backup.failed', description: __('Files backup failed: :reason', ['reason' => $e->label()]));
            $this->error('Files backup failed: '.$e->reason);

            return self::FAILURE;
        }

        if ($result === null) {
            $this->info('Nothing to back up: no uploaded images and no private catalog file.');

            return self::SUCCESS;
        }

        $logger->log(
            'backup.files_created',
            subjectLabel: $result['name'],
            description: __('Files backup created: :files files, :size', [
                'files' => $result['files'],
                'size' => BackupStorage::humanSize($result['bytes']),
            ]),
        );
        $this->info("Created {$result['name']} ({$result['files']} files, ".BackupStorage::humanSize($result['bytes']).'), deleted '.count($result['deleted']).' old.');

        return self::SUCCESS;
    }
}
