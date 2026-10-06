<?php

namespace App\Http\Requests;

use App\Models\IssuerProfile;
use Illuminate\Foundation\Http\FormRequest;

/** The details of the offer issuer (5.3). Only the name is required; empty fields are not printed. */
class UpdateIssuerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', IssuerProfile::current()) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'digits:5'],
            'city' => ['nullable', 'string', 'max:100'],
            'pib' => ['nullable', 'digits:9'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\/.\s-]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('Name of the issuer'),
            'address' => __('Address'),
            'postal_code' => __('Postal code'),
            'city' => __('City'),
            'pib' => 'PIB',
            'phone' => __('Phone'),
            'email' => __('Email'),
        ];
    }
}
