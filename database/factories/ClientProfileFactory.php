<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\ClientProfile;
use App\Models\User;
use App\Support\Jmbg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientProfile>
 */
class ClientProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Bare user: the client() user state already creates its own empty profile.
            'user_id' => User::factory(),
            'type' => ClientType::Individual,
            'full_name' => fake()->name(),
            'jmbg' => $this->jmbg(),
            'pib' => null,
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'country' => 'RS',
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'type' => ClientType::Company,
            'full_name' => fake()->company(),
            'jmbg' => null,
            'pib' => fake()->numerify('#########'),
        ]);
    }

    /**
     * Fake JMBG with a valid check digit: DDMM + YYY + region 71 + 3-digit serial.
     */
    private function jmbg(): string
    {
        $first12 = fake()->date('dm').fake()->numerify('###').'71'.fake()->numerify('###');

        return $first12.Jmbg::checkDigit($first12);
    }
}
