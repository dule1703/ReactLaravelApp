<?php

namespace App\Http\Requests\Admin;

use App\Enums\EquipmentAvailability;
use Illuminate\Validation\Validator;

class UpdateEquipmentPriceRequest extends PriceInputRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('trimEquipment')) ?? false;
    }

    protected function minNetCents(): int
    {
        return 0;
    }

    public function withValidator(Validator $validator): void
    {
        // Standard equipment carries no price of its own.
        $validator->after(function (Validator $validator) {
            if ($this->route('trimEquipment')->availability !== EquipmentAvailability::Optional) {
                $validator->errors()->add('amount', __('Only optional equipment has a price.'));
            }
        });
    }
}
