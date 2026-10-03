<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Models\Engine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Engine>
 */
class EngineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Motor '.fake()->unique()->lexify('????'),
            'fuel_type' => fake()->randomElement(FuelType::cases()),
            'power_kw' => fake()->numberBetween(60, 250),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
