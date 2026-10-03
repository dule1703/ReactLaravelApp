<?php

namespace App\Http\Requests;

use App\Enums\ClientType;
use App\Models\ClientProfile;
use App\Support\Jmbg;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a client profile update. Required identifiers depend on the type: JMBG for an
 * individual, PIB for a company; the other one is optional. A field that is absent from the
 * input is left out of validated(), so switching the type never wipes a stored JMBG/PIB.
 * JMBG is required for an individual only while none is stored, and a blank input never
 * clears a stored one.
 */
class UpdateClientProfileRequest extends FormRequest
{
    private ?ClientProfile $profile = null;

    /**
     * Bind the profile explicitly (otherwise the `clientProfile` route parameter, then the
     * authenticated client's own profile is used).
     */
    public function forProfile(ClientProfile $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function clientProfile(): ?ClientProfile
    {
        $routed = $this->route('clientProfile');

        return $this->profile ??= $routed instanceof ClientProfile ? $routed : $this->user()?->profile();
    }

    public function authorize(): bool
    {
        $profile = $this->clientProfile();

        return $profile !== null && $this->user()?->can('update', $profile) === true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        // Only whitespace and dashes are formatting; anything else (e.g. "0101990710008x")
        // must reach the digits:13 rule and fail.
        if (is_string($this->input('jmbg'))) {
            $jmbg = preg_replace('/[\s-]/', '', $this->input('jmbg'));
            $clean['jmbg'] = $jmbg === '' ? null : $jmbg;
        }

        foreach (['pib', 'postal_code', 'full_name', 'address', 'city', 'country'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $clean[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($clean);

        // A blank JMBG means "keep the stored one": drop it so validated() never carries a
        // null that would erase it. Whether it is required is decided in rules().
        if ($this->has('jmbg') && $this->input('jmbg') === null) {
            $this->getInputSource()->remove('jmbg');
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCompany = $this->input('type') === ClientType::Company->value;

        return [
            'type' => ['required', Rule::enum(ClientType::class)],
            // Free text on purpose: company names carry digits and symbols, names carry diacritics.
            'full_name' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'jmbg' => [
                'bail',
                // Required for an individual only while the profile has no JMBG yet.
                Rule::requiredIf(fn () => ! $isCompany && $this->clientProfile()?->jmbg_hash === null),
                'nullable',
                'digits:13',
                $this->validJmbg(...),
                $this->uniqueJmbg(...),
            ],
            'pib' => [$isCompany ? 'required' : 'sometimes', 'nullable', 'digits:9'],
            'address' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'postal_code' => ['required', 'digits:5'],
            'city' => ['required', 'string', 'max:100', 'regex:/^[^\p{C}]+$/u'],
            'country' => ['required', 'size:2', Rule::in(config('countries.codes'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // One message for every JMBG failure, so a duplicate is indistinguishable from a typo.
        return [
            'jmbg.digits' => __('The JMBG is not valid.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('Client type'),
            'full_name' => __('Full name or company name'),
            'jmbg' => 'JMBG',
            'pib' => 'PIB',
        ];
    }

    private function validJmbg(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! Jmbg::isValid((string) $value)) {
            $fail(__('The JMBG is not valid.'));
        }
    }

    /**
     * Compared by hash: the jmbg column is encrypted and cannot be searched.
     */
    private function uniqueJmbg(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || ! Jmbg::isValid((string) $value)) {
            return;
        }

        $taken = ClientProfile::where('jmbg_hash', Jmbg::hash((string) $value))
            ->when($this->clientProfile(), fn ($query, $own) => $query->whereKeyNot($own->getKey()))
            ->exists();

        if ($taken) {
            $fail(__('The JMBG is not valid.'));
        }
    }
}
