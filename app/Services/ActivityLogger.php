<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogger
{
    /**
     * Write one entry. Never throws: a logging failure must not break the user's action.
     *
     * Actor: the given/authenticated user; otherwise "guest" during an HTTP request and
     * "system" outside one (cron, artisan, seeders).
     *
     * @param  array<string, mixed>  $changes  already redacted, see changesFor()
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $changes = [],
        ?string $subjectLabel = null,
        ?User $actor = null,
        ?string $actorType = null,
    ): ?ActivityLog {
        try {
            $actor ??= Auth::user();
            $isHttp = request()->route() !== null;
            $type = $actorType ?? ($actor ? 'user' : ($isHttp ? 'guest' : 'system'));

            if ($type !== 'user') {
                $actor = null;
            }

            return ActivityLog::create([
                'created_at' => now(),
                'actor_type' => $type,
                'user_id' => $actor?->getKey(),
                'user_name' => $actor?->name,
                'user_email' => $actor?->email,
                'user_role' => $actor?->role?->value,
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ?? ($subject ? $this->labelFor($subject) : null),
                'description' => $description ? Str::limit($description, 500, '') : null,
                'changes' => $changes ?: null,
                'ip' => $isHttp ? request()->ip() : null,
                'user_agent' => $isHttp ? Str::limit((string) request()->userAgent(), 1000, '') : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Log a created/updated/deleted event of a model (used by the LogsActivity trait).
     */
    public function logModel(Model $model, string $event): ?ActivityLog
    {
        $changes = $this->changesFor($model, $event);

        if ($event === 'updated' && $changes === []) {
            return null;
        }

        $action = method_exists($model, 'activityAction')
            ? $model->activityAction($event, $changes)
            : Str::snake(class_basename($model)).'.'.$event;

        return $this->log($action, $model, changes: $changes);
    }

    /**
     * Sensitive fields (config activity-log.sensitive and $hidden) are reduced to their name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function changesFor(Model $model, string $event): array
    {
        $attributes = Arr::except(
            $event === 'updated' ? $model->getChanges() : $model->getAttributes(),
            config('activity-log.ignored'),
        );
        $sensitive = array_merge(config('activity-log.sensitive'), $model->getHidden());
        $changes = [];

        foreach ($attributes as $field => $value) {
            $changes[$field] = match (true) {
                in_array($field, $sensitive, true) => ['redacted' => true],
                $event === 'created' => ['new' => $value],
                $event === 'deleted' => ['old' => $value],
                default => ['old' => $model->getRawOriginal($field), 'new' => $value],
            };
        }

        return $changes;
    }

    private function labelFor(Model $subject): string
    {
        return method_exists($subject, 'activityLabel')
            ? $subject->activityLabel()
            : class_basename($subject).' #'.$subject->getKey();
    }
}
