<?php

namespace Database\Seeders;

use App\Models\IssuerProfile;
use Illuminate\Database\Seeder;

/**
 * A made-up issuer for local and staging, so a PDF is not empty while developing. DatabaseSeeder runs
 * it ONLY there: on production a made-up dealer would end up on real offers. It never overwrites what
 * an admin entered: it does nothing when the row exists.
 */
class IssuerProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (IssuerProfile::query()->exists()) {
            return;
        }

        IssuerProfile::create([
            'name' => 'Demo Auto d.o.o. (demo)',
            'address' => 'Bulevar Demonstracije 1',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'pib' => '000000000',
            'phone' => '+381 11 000 0000',
            'email' => 'office@demo-auto.example',
        ]);
    }
}
