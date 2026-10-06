<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\User;

/**
 * The props of the client start page (6.2), built in one place with an explicit list of keys, so a
 * test can lock them and nothing else (JMBG, PIB, address, other clients' offers) can reach the
 * browser. Two queries: the profile (User::profile() is a firstOrCreate) and the last offers,
 * narrowed by user_id in the query. Deleted offers are left out by the default scope of Offer.
 */
class ClientDashboard
{
    public const RECENT_OFFERS = 5;

    /**
     * @return array{name: string, profile_missing: list<string>, recent_offers: list<array<string, mixed>>}
     */
    public static function data(User $client): array
    {
        $profile = $client->profile();

        return [
            // The account name: the profile's full name can be empty.
            'name' => $client->name,
            'profile_missing' => $profile === null ? [] : OfferClientRules::missing($profile),
            'recent_offers' => self::recentOffers($client),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentOffers(User $client): array
    {
        return Offer::query()
            ->where('user_id', $client->id)
            ->orderByDesc('offer_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_OFFERS)
            ->get(['id', 'number', 'offer_date', 'total_gross_cents', 'withdrawn_at'])
            ->map(fn (Offer $offer) => [
                'id' => $offer->id,
                'number' => $offer->number,
                'offer_date' => $offer->offer_date->format('d.m.Y'),
                'total_gross_cents' => $offer->total_gross_cents,
                'withdrawn_at' => $offer->withdrawn_at?->format('d.m.Y'),
            ])
            ->all();
    }
}
