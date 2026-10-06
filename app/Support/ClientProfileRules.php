<?php

namespace App\Support;

use App\Enums\ClientType;
use App\Models\ClientProfile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The rules of the client profile fields in ONE place, shared by the client's own profile and the
 * admin edit (UpdateClientProfileRequest) and by the admin creating a client (StoreClientRequest).
 * Required identifiers depend on the type: PIB for a company; the JMBG is required for an
 * individual only where the caller says so (the client's own profile while none is stored; never
 * in the salon flow and never for an admin).
 */
class ClientProfileRules
{
    /** Free text on purpose: company names carry digits and symbols, names carry diacritics. */
    private const TEXT = 'regex:/^[^\p{C}]+$/u';

    /**
     * The input with the formatting removed: JMBG without whitespace and dashes (anything else,
     * e.g. "0101990710008x", must reach digits:13 and fail), text fields trimmed, "" -> null.
     * Only keys that are present in the input are returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $clean = [];

        if (isset($input['jmbg']) && is_string($input['jmbg'])) {
            $jmbg = preg_replace('/[\s-]/', '', $input['jmbg']);
            $clean['jmbg'] = $jmbg === '' ? null : $jmbg;
        }

        foreach (['pib', 'postal_code', 'full_name', 'address', 'city', 'country'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $value = trim($input[$field]);
                $clean[$field] = $value === '' ? null : $value;
            }
        }

        return $clean;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public static function fields(bool $isCompany): array
    {
        return [
            'type' => ['required', Rule::enum(ClientType::class)],
            'full_name' => ['required', 'string', 'max:255', self::TEXT],
            'pib' => [$isCompany ? 'required' : 'sometimes', 'nullable', 'digits:9'],
            'address' => ['required', 'string', 'max:255', self::TEXT],
            'postal_code' => ['required', 'digits:5'],
            'city' => ['required', 'string', 'max:100', self::TEXT],
            'country' => ['required', 'size:2', Rule::in(config('countries.codes'))],
        ];
    }

    /**
     * @param  bool  $required  whether the JMBG must be given
     * @param  ClientProfile|null  $own  the profile being edited (excluded from the uniqueness check)
     * @param  bool  $unique  false when the caller reports duplicates itself (the salon flow names the existing client)
     * @return array<int, mixed>
     */
    public static function jmbg(bool $required, ?ClientProfile $own = null, bool $unique = true): array
    {
        return array_values(array_filter([
            'bail',
            $required ? 'required' : null,
            'nullable',
            'digits:13',
            self::validJmbg(...),
            $unique ? self::uniqueJmbg($own) : null,
        ]));
    }

    /**
     * One message for every JMBG failure, so on the client's own page a duplicate is
     * indistinguishable from a typo.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['jmbg.digits' => __('The JMBG is not valid.')];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'type' => __('Client type'),
            'full_name' => __('Full name or company name'),
            'jmbg' => 'JMBG',
            'pib' => 'PIB',
        ];
    }

    private static function validJmbg(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! Jmbg::isValid((string) $value)) {
            $fail(__('The JMBG is not valid.'));
        }
    }

    /** Compared by hash: the jmbg column is encrypted and cannot be searched. */
    private static function uniqueJmbg(?ClientProfile $own): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($own): void {
            if ($value === null || ! Jmbg::isValid((string) $value)) {
                return;
            }

            $taken = ClientProfile::where('jmbg_hash', Jmbg::hash((string) $value))
                ->when($own, fn ($query, $profile) => $query->whereKeyNot($profile->getKey()))
                ->exists();

            if ($taken) {
                $fail(__('The JMBG is not valid.'));
            }
        };
    }
}
