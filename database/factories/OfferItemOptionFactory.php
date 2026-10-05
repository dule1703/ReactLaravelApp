<?php

namespace Database\Factories;

use App\Models\OfferItem;
use App\Models\OfferItemOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferItemOption>
 */
class OfferItemOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_item_id' => OfferItem::factory(),
            'position' => 0,
            'name' => 'Climatronic',
            'category' => 'comfort',
            'group_name' => null,
            'is_surcharge' => false,
            'price_cents' => 41_200,
        ];
    }

    /** A surcharge over the standard item of a single-choice group. */
    public function surcharge(string $group = 'Točkovi'): static
    {
        return $this->state(fn () => ['group_name' => $group, 'is_surcharge' => true]);
    }
}
