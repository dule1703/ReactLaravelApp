<?php

namespace App\Http\Requests\Admin;

use App\Models\ClientProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientIndexRequest extends FormRequest
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClientProfile::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ];
    }
}
