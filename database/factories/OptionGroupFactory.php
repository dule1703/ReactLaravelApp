<?php

namespace Database\Factories;

use App\Enums\EquipmentCategory;
use App\Enums\OptionSelection;
use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OptionGroup>
 */
class OptionGroupFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Grupa '.fake()->unique()->lexify('????');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => EquipmentCategory::Exterior,
            'selection' => OptionSelection::Single,
            'uses_swatch' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function multiple(): static
    {
        return $this->state(fn () => ['selection' => OptionSelection::Multiple]);
    }

    public function swatch(): static
    {
        return $this->state(fn () => ['uses_swatch' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
