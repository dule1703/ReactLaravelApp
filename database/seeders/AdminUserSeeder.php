<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the demo admin. The password comes from SEED_ADMIN_PASSWORD so no known
     * password ever lives in the (public) repository. Existing admins are left untouched.
     */
    public function run(): void
    {
        $password = config('app.seed_admin_password');

        if (blank($password)) {
            $this->command?->warn('SEED_ADMIN_PASSWORD is not set; skipping admin user.');

            return;
        }

        $admin = User::firstOrNew(['email' => 'admin@example.com']);

        if ($admin->exists) {
            return;
        }

        // `role` is not mass-assignable, so it is set explicitly.
        $admin->forceFill([
            'name' => 'Demo Admin',
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();
    }
}
