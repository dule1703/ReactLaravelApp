<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// Model events stay on (no WithoutModelEvents): the JMBG hash is computed by a model event.
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        User::factory()->client()->create([
            'name' => 'Demo Client',
            'email' => 'client@example.com',
        ]);
        User::factory()->client()->create([
            'name' => 'Demo Firma',
            'email' => 'firma@example.com',
        ]);

        $this->call(ClientProfileSeeder::class);

        // A made-up issuer only where nobody makes real offers: on production it would be printed on them.
        if (app()->environment(['local', 'staging'])) {
            $this->call(IssuerProfileSeeder::class);
        }

        // Real catalog from database/seeders/data/real_catalog.php (an empty frame does nothing).
        // The demo catalog stays available for development: CatalogSeeder, then CategorySeeder.
        $this->call(RealCatalogSeeder::class);
    }
}
