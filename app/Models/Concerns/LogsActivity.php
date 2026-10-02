<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogger;

/**
 * Logs create/update/delete of a model to the activity log. Optional hooks on the model:
 *  - activityLabel(): string  label stored with the entry, e.g. "Client #12 Petar Petrovic"
 *  - activityAction(string $event, array $changes): string  override the action code
 * Add sensitive columns to config/activity-log.php so their values are never stored.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(fn ($model) => app(ActivityLogger::class)->logModel($model, $event));
        }
    }
}
