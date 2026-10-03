<?php

namespace Database\Factories;

use App\Enums\DriveType;
use App\Enums\TransmissionType;
use App\Models\Transmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transmission>
 */
class TransmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Menjac '.fake()->unique()->lexify('????'),
            'type' => fake()->randomElement(TransmissionType::cases()),
            'drive' => DriveType::Fwd,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
