<?php

namespace App\Http\Requests;

use App\Enums\ClientType;
use App\Models\ClientProfile;
use App\Support\ClientProfileRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a client profile update (the client's own page and the admin edit); the field rules
 * are in ClientProfileRules. Required identifiers depend on the type: PIB for a company, the other
 * one is optional. A field that is absent from the input is left out of validated(), so switching
 * the type never wipes a stored JMBG/PIB. JMBG is required for an individual only while none is
 * stored and only when the client edits their OWN profile (an admin may leave it empty: a client
 * made in the salon has none), and a blank input never clears a stored one.
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
        $this->merge(ClientProfileRules::clean($this->all()));

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
        $own = $this->clientProfile();
        $jmbgRequired = ! $isCompany && $own?->jmbg_hash === null && $this->user()?->isAdmin() !== true;

        return [
            ...ClientProfileRules::fields($isCompany),
            'jmbg' => ClientProfileRules::jmbg($jmbgRequired, $own),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ClientProfileRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ClientProfileRules::attributes();
    }
}
