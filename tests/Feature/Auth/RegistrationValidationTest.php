<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationValidationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    public function test_registration_requires_name_email_and_password(): void
    {
        $this->post('/register', [])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertGuest();
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->post('/register', $this->payload())->assertSessionHasErrors('email');
    }

    public function test_email_must_be_lowercase_and_valid(): void
    {
        $this->post('/register', $this->payload(['email' => 'Test@Example.com']))
            ->assertSessionHasErrors('email');
        $this->post('/register', $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->post('/register', $this->payload(['password_confirmation' => 'different']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registered_user_is_logged_in_and_redirected_to_dashboard(): void
    {
        $this->post('/register', $this->payload())->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'name' => 'Test User']);
    }
}
