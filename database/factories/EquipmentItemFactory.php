<?php

namespace Database\Factories;

use App\Enums\EquipmentCategory;
use App\Models\EquipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentItem>
 */
class EquipmentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Oprema '.fake()->unique()->lexify('??????'),
            'category' => fake()->randomElement(EquipmentCategory::cases()),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
