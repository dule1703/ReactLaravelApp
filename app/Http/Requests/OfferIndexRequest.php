<?php

namespace App\Http\Requests;

use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfferIndexRequest extends FormRequest
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /** Status filter: `all` = every offer that is not deleted. */
    public const STATUSES = ['all', 'active', 'withdrawn'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Offer::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ];
    }
}
