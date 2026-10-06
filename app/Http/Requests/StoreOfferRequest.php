<?php

namespace App\Http\Requests;

use App\Models\Offer;
use App\Support\OfferCalculator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving an offer from the configurator. The depth of `items` (ids, quantity, option_ids) is
 * checked by OfferItemResolver, not here. The expected totals are required whole numbers: the
 * service treats null as "no check", so the controller must never pass one without them.
 *
 * `client_id` (the id of a client PROFILE) is required for an admin, who makes the offer on behalf
 * of that client, and is not read at all from a client: a client's offer is always their own.
 */
class StoreOfferRequest extends FormRequest
{
    public const NOTE_MAX = 1000;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Offer::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.OfferCalculator::MAX_ITEMS],
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            'expected_total_net_cents' => ['required', 'integer:strict', 'min:0'],
            'expected_total_gross_cents' => ['required', 'integer:strict', 'min:0'],
            ...($this->user()?->isAdmin() ? ['client_id' => ['required', 'integer:strict', 'min:1', 'exists:client_profiles,id']] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => __('Offer items'),
            'note' => __('Note'),
            'expected_total_net_cents' => __('Total without VAT'),
            'expected_total_gross_cents' => __('Total with VAT'),
            'client_id' => __('Client'),
        ];
    }
}
