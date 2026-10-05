<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Http\Requests\Admin\PriceInputRequest;
use App\Models\Version;
use Illuminate\Validation\Rule;

/**
 * A new version (trim + engine + transmission) with its price typed as NET or GROSS: the same
 * price input as /admin/prices, so the server derives the net price itself. The combination is
 * unique and never changes afterwards; later only the status and the price change.
 */
class StoreVersionRequest extends PriceInputRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Version::class) ?? false;
    }

    protected function minNetCents(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'trim_id' => [
                'required',
                'integer',
                'exists:trims,id',
                Rule::unique('versions', 'trim_id')
                    ->where('engine_id', $this->input('engine_id'))
                    ->where('transmission_id', $this->input('transmission_id')),
            ],
            'engine_id' => ['required', 'integer', 'exists:engines,id'],
            'transmission_id' => ['required', 'integer', 'exists:transmissions,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['trim_id.unique' => __('This combination of trim, engine and transmission already exists.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'trim_id' => __('catalog.attr.trim_id'),
            'engine_id' => __('catalog.attr.engine_id'),
            'transmission_id' => __('catalog.attr.transmission_id'),
            'amount' => __('catalog.attr.amount'),
            'mode' => __('catalog.attr.mode'),
        ];
    }
}
