<?php

namespace App\Support;

use App\Models\ClientProfile;

/**
 * The client details copied into an offer. An explicit list of fields: the JMBG is never read,
 * not even masked.
 */
class ClientSnapshot
{
    /**
     * @return array<string, string|null> `offers.client_*` columns
     */
    public static function from(ClientProfile $profile): array
    {
        $pib = trim((string) $profile->pib);

        return [
            'client_type' => $profile->type->value,
            'client_name' => trim((string) $profile->full_name),
            'client_pib' => $pib === '' ? null : $pib,
            'client_address' => trim((string) $profile->address),
            'client_postal_code' => trim((string) $profile->postal_code),
            'client_city' => trim((string) $profile->city),
            'client_country' => trim((string) $profile->country),
        ];
    }
}
