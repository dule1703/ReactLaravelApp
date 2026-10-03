<?php

namespace Database\Factories;

use App\Models\Engine;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a consistent chain: version -> trim -> car model, plus engine and transmission.
 *
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trim_id' => Trim::factory(),
            'engine_id' => Engine::factory(),
            'transmission_id' => Transmission::factory(),
            // Integer cents, net of VAT: 15,000.00 to 60,000.00 EUR in whole-euro steps.
            'base_price_cents' => fake()->numberBetween(15000, 60000) * 100,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
