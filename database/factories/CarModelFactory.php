<?php

namespace Database\Factories;

use App\Models\CarModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CarModel>
 */
class CarModelFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Model '.fake()->unique()->lexify('????');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
