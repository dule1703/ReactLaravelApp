<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferItem>
 */
class OfferItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'position' => 0,
            'quantity' => 1,
            'car_model_name' => 'Fabia',
            'trim_name' => 'Essence',
            'engine_name' => '1.0 MPI',
            'fuel_type' => 'petrol',
            'power_kw' => 59,
            'transmission_name' => 'Manuelni 5 brzina',
            'drive' => 'fwd',
            'version_price_cents' => 1_500_000,
            'line_net_cents' => null,
        ];
    }
}
