<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record. Create it through App\Services\ActivityLogger.
 * Updating or deleting a row through Eloquent is blocked; the only deletion path is the
 * retention command (activitylog:prune), which works on the query builder.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Activity log entries are append-only.'));
    }
}
