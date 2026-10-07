<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityLogFilterRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Like;
use App\Support\UserAgent;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(ActivityLogFilterRequest $request): Response
    {
        $filters = $request->validated();

        $logs = ActivityLog::query()
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('user_role', $role))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($filters['ip'] ?? null, fn ($q, $ip) => $q->whereRaw("ip like ? escape '!'", [Like::startsWith($ip)]))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = Like::contains($term);

                $q->where(fn ($q) => $q
                    ->whereRaw("user_name like ? escape '!'", [$like])
                    ->orWhereRaw("user_email like ? escape '!'", [$like])
                    ->orWhereRaw("subject_label like ? escape '!'", [$like])
                    ->orWhereRaw("description like ? escape '!'", [$like]));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'time' => $log->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
                'actor_type' => $log->actor_type,
                'user_name' => $log->user_name,
                'user_email' => $log->user_email,
                'user_role' => $log->user_role,
                'action' => $log->action,
                'subject_label' => $log->subject_label,
                'description' => $log->description,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'device' => UserAgent::summary($log->user_agent),
            ]);

        return Inertia::render('Admin/ActivityLog', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
