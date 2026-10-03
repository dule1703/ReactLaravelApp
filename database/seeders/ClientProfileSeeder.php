<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Models\User;
use App\Support\Jmbg;
use Illuminate\Database\Seeder;

class ClientProfileSeeder extends Seeder
{
    /**
     * Fill the profiles of the demo clients with fake data (the repository is public).
     * Requires the demo users from DatabaseSeeder.
     */
    public function run(): void
    {
        // 01.01.990 + region 71 + serial 001, with a valid check digit.
        $first12 = '010199071001';

        User::where('email', 'client@example.com')->firstOrFail()->clientProfile->update([
            'type' => ClientType::Individual,
            'full_name' => 'Petar Petrovic',
            'jmbg' => $first12.Jmbg::checkDigit($first12),
            'address' => 'Bulevar kralja Aleksandra 1',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'country' => 'RS',
        ]);

        User::where('email', 'firma@example.com')->firstOrFail()->clientProfile->update([
            'type' => ClientType::Company,
            'full_name' => 'Demo Auto d.o.o.',
            'pib' => '100000001',
            'address' => 'Nemanjina 10',
            'postal_code' => '21000',
            'city' => 'Novi Sad',
            'country' => 'RS',
        ]);
    }
}
