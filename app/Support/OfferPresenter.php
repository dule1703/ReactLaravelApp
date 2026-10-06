<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use Illuminate\Support\Str;

/**
 * The props of the offer pages, built from the SNAPSHOT stored in the offer (never from the live
 * client profile or the catalog). Every key is listed explicitly, so nothing else (a JMBG, a hash,
 * a profile attribute) can reach the browser by accident; a test locks the exact lists.
 * Amounts stay integer cents (null when the offer has no totals) and are formatted by the page.
 */
class OfferPresenter
{
    /**
     * A row of the list. The client name and the date of deletion are only for the admin (a client
     * sees their own offers, never a deleted one).
     *
     * @return array<string, mixed>
     */
    public static function row(Offer $offer, bool $admin): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'offer_date' => $offer->offer_date->format('d.m.Y'),
            ...($admin ? ['client_name' => $offer->client_name, 'deleted_at' => $offer->deleted_at?->format('d.m.Y')] : []),
            'vat_rate_bp' => $offer->vat_rate_bp,
            'total_net_cents' => $offer->total_net_cents,
            'vat_cents' => $offer->vat_cents,
            'total_gross_cents' => $offer->total_gross_cents,
            'withdrawn_at' => $offer->withdrawn_at?->format('d.m.Y'),
            'note' => $offer->note === null ? null : Str::limit($offer->note, 120),
            'items_count' => (int) $offer->items_count,
        ];
    }

    /**
     * One offer with its items and options. `client_profile_id` (a link to the client) is only
     * given to the admin; the caller loads `items.options` (and, for the admin, `user.clientProfile`).
     *
     * @return array<string, mixed>
     */
    public static function detail(Offer $offer, bool $admin): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'offer_date' => $offer->offer_date->format('d.m.Y'),
            'vat_rate_bp' => $offer->vat_rate_bp,
            'note' => $offer->note,
            'withdrawn_at' => $offer->withdrawn_at?->format('d.m.Y'),
            'total_net_cents' => $offer->total_net_cents,
            'vat_cents' => $offer->vat_cents,
            'total_gross_cents' => $offer->total_gross_cents,
            'client' => [
                'type' => $offer->client_type,
                'name' => $offer->client_name,
                'pib' => $offer->client_pib,
                'address' => $offer->client_address,
                'postal_code' => $offer->client_postal_code,
                'city' => $offer->client_city,
                'country' => self::country($offer->client_country),
            ],
            ...($admin ? ['client_profile_id' => $offer->user?->clientProfile?->id] : []),
            'items' => $offer->items->map(fn (OfferItem $item) => [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'car_model_name' => $item->car_model_name,
                'trim_name' => $item->trim_name,
                'engine_name' => $item->engine_name,
                'fuel_type' => $item->fuel_type,
                'power_kw' => $item->power_kw,
                'transmission_name' => $item->transmission_name,
                'drive' => $item->drive,
                'version_price_cents' => $item->version_price_cents,
                'line_net_cents' => $item->line_net_cents,
                'options' => $item->options->map(fn (OfferItemOption $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'category' => $option->category,
                    'group_name' => $option->group_name,
                    'is_surcharge' => $option->is_surcharge,
                    'price_cents' => $option->price_cents,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The issuer (dealer) copied into the offer, for the header of the PDF ONLY: the pages of the
     * offer do not get it (detail() has no such key). Null when the offer has none (an older offer,
     * or the details were not entered): the PDF then prints the plain header.
     *
     * @return array{name: string, address: ?string, postal_code: ?string, city: ?string, pib: ?string, phone: ?string, email: ?string}|null
     */
    public static function issuer(Offer $offer): ?array
    {
        if (! filled($offer->issuer_name)) {
            return null;
        }

        return [
            'name' => $offer->issuer_name,
            'address' => $offer->issuer_address,
            'postal_code' => $offer->issuer_postal_code,
            'city' => $offer->issuer_city,
            'pib' => $offer->issuer_pib,
            'phone' => $offer->issuer_phone,
            'email' => $offer->issuer_email,
        ];
    }

    /** The country name, or the stored code when the config no longer knows it. */
    private static function country(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $name = __("country.$code");

        return $name === "country.$code" ? $code : $name;
    }
}
