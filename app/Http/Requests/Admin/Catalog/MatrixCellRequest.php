<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\EquipmentAvailability;
use App\Http\Requests\Admin\PriceInputRequest;
use App\Models\EquipmentItem;
use App\Models\Trim;
use App\Models\TrimEquipment;
use Illuminate\Validation\Rule;

/**
 * One cell of the equipment matrix. The price is typed net OR gross and the server derives the
 * net price (PriceInputRequest); the state the browser saw travels with the request so the
 * service can detect a concurrent change.
 */
class MatrixCellRequest extends PriceInputRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrimEquipment::class) ?? false;
    }

    protected function minNetCents(): int
    {
        return 0;
    }

    public function rules(): array
    {
        $states = ['none', ...array_column(EquipmentAvailability::cases(), 'value')];
        $optional = $this->input('availability') === 'optional';
        $swap = $this->input('previous.availability') === 'optional';

        return [
            'car_model_id' => ['required', 'integer', 'exists:car_models,id'],
            'trim_id' => ['required', 'integer', Rule::exists('trims', 'id')->where('car_model_id', $this->input('car_model_id'))],
            'equipment_item_id' => ['required', 'integer', 'exists:equipment_items,id'],
            'availability' => ['required', Rule::in($states)],
            'mode' => [Rule::requiredIf($optional), 'nullable', Rule::in(['net', 'gross'])],
            'amount' => $optional ? $this->amountRules('mode') : ['nullable'],
            'expected' => ['required', 'array'],
            'expected.availability' => ['required', Rule::in($states)],
            'expected.price_cents' => ['nullable', 'integer'],
            'expected_standard_item_id' => ['nullable', 'integer'],
            'previous' => ['nullable', 'array'],
            'previous.availability' => ['required_with:previous', Rule::in(['none', 'optional'])],
            'previous.mode' => [Rule::requiredIf($swap), 'nullable', Rule::in(['net', 'gross'])],
            'previous.amount' => $swap ? $this->amountRules('previous.mode') : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return ['trim_id.exists' => __('The line does not belong to the selected model.')];
    }

    public function trim(): Trim
    {
        return Trim::query()->findOrFail($this->integer('trim_id'));
    }

    public function item(): EquipmentItem
    {
        return EquipmentItem::query()->with('group')->findOrFail($this->integer('equipment_item_id'));
    }

    /** Net price of the typed amount, or null when the target is not "optional". */
    public function targetPriceCents(): ?int
    {
        return $this->input('availability') === 'optional' ? $this->netCents() : null;
    }

    /**
     * @return array{availability: 'none'|'optional', price_cents: ?int}|null
     */
    public function previous(): ?array
    {
        $previous = $this->input('previous');

        if (! is_array($previous)) {
            return null;
        }

        return [
            'availability' => $previous['availability'],
            'price_cents' => $previous['availability'] === 'optional' ? $this->netCentsOf('previous.mode', 'previous.amount') : null,
        ];
    }

    /**
     * @return array{availability: string, price_cents: ?int}
     */
    public function expected(): array
    {
        return [
            'availability' => $this->input('expected.availability'),
            'price_cents' => $this->input('expected.price_cents') === null ? null : (int) $this->input('expected.price_cents'),
        ];
    }
}
