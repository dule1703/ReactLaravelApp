<?php

namespace App\Http\Requests\Admin;

use App\Enums\ClientType;
use App\Models\ClientProfile;
use App\Support\ClientProfileRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin creates a client (a user with the role client and a profile) in the salon flow. The
 * field rules are the shared ones (ClientProfileRules) with the JMBG OPTIONAL; the email is
 * required. There is no password and no role in the input: the password is random and unknown to
 * everyone, the role is set by the server. Duplicates (email, JMBG, PIB) are reported by
 * ClientCreator inside the transaction that writes, with a link to the existing client.
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClientProfile::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $clean = ClientProfileRules::clean($this->all());

        if (is_string($this->input('email'))) {
            $clean['email'] = mb_strtolower(trim($this->input('email')));
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCompany = $this->input('type') === ClientType::Company->value;

        return [
            ...ClientProfileRules::fields($isCompany),
            'email' => ['required', 'string', 'email', 'max:255'],
            'jmbg' => ClientProfileRules::jmbg(required: false, unique: false),
            // The PIB of an existing client is only a warning (two branches of one company): the
            // admin confirms it with this flag.
            'confirm_duplicate_pib' => ['sometimes', 'boolean'],
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
        return [...ClientProfileRules::attributes(), 'email' => __('Email')];
    }
}
