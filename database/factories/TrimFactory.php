<?php

namespace Database\Factories;

use App\Models\CarModel;
use App\Models\Trim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trim>
 */
class TrimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_model_id' => CarModel::factory(),
            'name' => 'Paket '.fake()->unique()->lexify('????'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
