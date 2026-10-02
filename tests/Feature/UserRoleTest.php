<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_clients_by_default(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(UserRole::Client, $user->fresh()->role);
        $this->assertTrue($user->isClient());
        $this->assertFalse($user->isAdmin());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(UserRole::Client, $user->fresh()->role);
    }

    public function test_registration_ignores_a_submitted_role(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(UserRole::Client, User::firstWhere('email', 'test@example.com')->role);
    }

    public function test_factory_states_set_the_role(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
        $this->assertTrue(User::factory()->client()->create()->isClient());
        $this->assertTrue(User::factory()->create()->isClient());
    }

    public function test_seeder_creates_an_admin_from_the_configured_password(): void
    {
        config(['app.seed_admin_password' => 'secret-for-test']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::firstWhere('email', 'admin@example.com');
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('secret-for-test', $admin->password));
    }

    public function test_seeder_skips_the_admin_without_a_password(): void
    {
        config(['app.seed_admin_password' => null]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_seeder_does_not_overwrite_an_existing_admins_password(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'changed-by-admin',
        ]);
        config(['app.seed_admin_password' => 'secret-for-test']);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('changed-by-admin', $admin->fresh()->password));
    }
}
