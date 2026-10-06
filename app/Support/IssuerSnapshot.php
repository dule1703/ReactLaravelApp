<?php

namespace App\Support;

use App\Models\IssuerProfile;

/**
 * The issuer (dealer) details copied into an offer: an explicit list of `offers.issuer_*` columns.
 * Without a name there is no issuer and every column is null (the offer prints the plain header).
 */
class IssuerSnapshot
{
    public const COLUMNS = ['issuer_name', 'issuer_address', 'issuer_postal_code', 'issuer_city', 'issuer_pib', 'issuer_phone', 'issuer_email'];

    /**
     * @return array<string, string|null>
     */
    public static function current(): array
    {
        $profile = IssuerProfile::current();
        $name = self::clean($profile->name);

        if ($name === null) {
            return array_fill_keys(self::COLUMNS, null);
        }

        return [
            'issuer_name' => $name,
            'issuer_address' => self::clean($profile->address),
            'issuer_postal_code' => self::clean($profile->postal_code),
            'issuer_city' => self::clean($profile->city),
            'issuer_pib' => self::clean($profile->pib),
            'issuer_phone' => self::clean($profile->phone),
            'issuer_email' => self::clean($profile->email),
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
