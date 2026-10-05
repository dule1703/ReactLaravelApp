<?php

namespace App\Support;

use App\Enums\ClientType;
use App\Models\ClientProfile;

/**
 * The one place that says when a client profile is complete enough to make an offer (the offer
 * service and, later, the UI use it). Same minimum as the profile form: name, address, postal
 * code, city, country, and the PIB for a company.
 */
class OfferClientRules
{
    /**
     * @return list<string> profile fields that are missing, empty when the profile is complete
     */
    public static function missing(ClientProfile $profile): array
    {
        $required = ['full_name', 'address', 'postal_code', 'city', 'country'];

        if ($profile->type === ClientType::Company) {
            $required[] = 'pib';
        }

        return array_values(array_filter(
            $required,
            fn (string $field) => trim((string) $profile->getAttribute($field)) === '',
        ));
    }
}
