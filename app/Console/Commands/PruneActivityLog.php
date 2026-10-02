<?php

namespace App\Console\Commands;

use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneActivityLog extends Command
{
    protected $signature = 'activitylog:prune {--days= : Keep this many days (default: ACTIVITY_LOG_RETENTION_DAYS)}';

    protected $description = 'Delete activity log entries older than the retention period';

    public function handle(ActivityLogger $logger): int
    {
        $days = (int) ($this->option('days') ?? config('activity-log.retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        // Query builder on purpose: the model blocks deletes so the app cannot erase history.
        $count = DB::table('activity_logs')->where('created_at', '<', now()->subDays($days))->delete();

        $logger->log('activitylog.pruned', description: __('Activity log pruned: :count records older than :days days.', [
            'count' => $count,
            'days' => $days,
        ]));

        $this->info("Deleted {$count} entries older than {$days} days.");

        return self::SUCCESS;
    }
}
