<?php

namespace Database\Factories;

use App\Enums\EquipmentAvailability;
use App\Models\EquipmentItem;
use App\Models\Trim;
use App\Models\TrimEquipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Standard equipment by default (no price); optional() adds a net price in cents.
 *
 * @extends Factory<TrimEquipment>
 */
class TrimEquipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trim_id' => Trim::factory(),
            'equipment_item_id' => EquipmentItem::factory(),
            'availability' => EquipmentAvailability::Standard,
            'price_cents' => null,
        ];
    }

    public function optional(?int $priceCents = null): static
    {
        return $this->state(fn () => [
            'availability' => EquipmentAvailability::Optional,
            // Whole-euro price in cents, net of VAT.
            'price_cents' => $priceCents ?? fake()->numberBetween(50, 3000) * 100,
        ]);
    }
}
