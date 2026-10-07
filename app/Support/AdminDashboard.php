<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\Offer;
use Illuminate\Support\Carbon;

/**
 * The props of the admin start page (6.1), built in one place with an explicit list of keys, so a
 * test can lock them and nothing else (JMBG, PIB, IP, device, the values of changes) can reach the
 * browser. Four queries, none per row: client count, offer counts, last offers, last activities.
 * Deleted offers are never counted (the default scope of Offer); withdrawn ones have their own count.
 */
class AdminDashboard
{
    public const RECENT_OFFERS = 5;

    public const RECENT_ACTIVITIES = 8;

    public const RECENT_DAYS = 30;

    /** Prefixes of actions left out of the activity list as noise (sign-ins stay in the full log). */
    public const HIDDEN_ACTION_PREFIXES = ['auth.'];

    /** Single actions left out of the activity list as noise (a PDF opened or downloaded, a successful backup). */
    public const HIDDEN_ACTIONS = ['offer.pdf_opened', 'offer.pdf_downloaded', 'backup.database_created', 'backup.files_created'];

    /**
     * @return array{counts: array<string, int>, recent_offers: list<array<string, mixed>>, recent_activities: list<array<string, mixed>>}
     */
    public static function data(): array
    {
        return [
            'counts' => self::counts(),
            'recent_offers' => self::recentOffers(),
            'recent_activities' => self::recentActivities(),
        ];
    }

    /** @return array{clients: int, active_offers: int, withdrawn_offers: int, offers_last_30_days: int} */
    private static function counts(): array
    {
        // The limit is a Y-m-d string in the application time zone; offer_date is a date column.
        $since = Carbon::now(config('app.timezone'))->subDays(self::RECENT_DAYS)->format('Y-m-d');

        $offers = Offer::query()
            ->selectRaw('coalesce(sum(case when withdrawn_at is null then 1 else 0 end), 0) as active')
            ->selectRaw('coalesce(sum(case when withdrawn_at is not null then 1 else 0 end), 0) as withdrawn')
            ->selectRaw('coalesce(sum(case when offer_date >= ? then 1 else 0 end), 0) as recent', [$since])
            ->toBase()
            ->first();

        return [
            'clients' => ClientProfile::query()->count(),
            'active_offers' => (int) $offers->active,
            'withdrawn_offers' => (int) $offers->withdrawn,
            'offers_last_30_days' => (int) $offers->recent,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentOffers(): array
    {
        return Offer::query()
            ->orderByDesc('offer_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_OFFERS)
            ->get(['id', 'number', 'client_name', 'offer_date', 'total_gross_cents', 'withdrawn_at'])
            ->map(fn (Offer $offer) => [
                'id' => $offer->id,
                'number' => $offer->number,
                'client_name' => $offer->client_name,
                'offer_date' => $offer->offer_date->format('d.m.Y'),
                'total_gross_cents' => $offer->total_gross_cents,
                'withdrawn_at' => $offer->withdrawn_at?->format('d.m.Y'),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function recentActivities(): array
    {
        $query = ActivityLog::query()->whereNotIn('action', self::HIDDEN_ACTIONS);

        foreach (self::HIDDEN_ACTION_PREFIXES as $prefix) {
            $query->whereRaw("action not like ? escape '!'", [Like::startsWith($prefix)]);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITIES)
            ->get(['id', 'created_at', 'actor_type', 'user_name', 'action', 'subject_label'])
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'time' => $log->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                'actor_type' => $log->actor_type,
                'user_name' => $log->user_name,
                'action' => $log->action,
                'subject_label' => $log->subject_label,
            ])
            ->all();
    }
}
