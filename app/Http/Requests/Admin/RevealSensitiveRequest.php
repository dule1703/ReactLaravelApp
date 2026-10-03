<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevealSensitiveRequest extends FormRequest
{
    /** Fields an admin may reveal; the only values ever read dynamically from the profile. */
    public const FIELDS = ['jmbg', 'pib'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewSensitive', $this->route('clientProfile')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(self::FIELDS)],
        ];
    }
}
