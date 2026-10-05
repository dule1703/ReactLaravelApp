<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An offer of a client with a unique number; totals stay null (the calculation, 4.4, fills them).
 *
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        $year = (int) now()->format('Y');
        $seq = fake()->unique()->numberBetween(1, 999_999);

        return [
            'user_id' => User::factory()->client(),
            'year' => $year,
            'seq' => $seq,
            'number' => sprintf('%03d/%04d', $seq, $year),
            'offer_date' => now()->toDateString(),
            'vat_rate_bp' => 2000,
            'note' => null,
            'client_type' => 'individual',
            'client_name' => fake()->name(),
            'client_pib' => null,
            'client_address' => fake()->streetAddress(),
            'client_postal_code' => fake()->numerify('#####'),
            'client_city' => fake()->city(),
            'client_country' => 'RS',
        ];
    }
}
