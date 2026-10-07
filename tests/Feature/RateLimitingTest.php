<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Named limiters (6.3): each name has its own counter and the N+1st request is a 429. Request bodies
 * are empty on purpose: validation answers (302/422) are not 429, only the limiter is under test.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string, int}> name => [limiter, method, uri, who] */
    public static function limitedRoutes(): array
    {
        return [
            'register' => ['auth-guest', 'POST', '/register', 'guest'],
            'reset password' => ['auth-guest', 'POST', '/reset-password', 'guest'],
            'forgot password' => ['auth-forgot', 'POST', '/forgot-password', 'guest'],
            'login (per IP)' => ['login-ip', 'POST', '/login', 'guest'],
            'confirm password' => ['password-sensitive', 'POST', '/confirm-password', 'client'],
            'change password' => ['password-sensitive', 'PUT', '/password', 'client'],
            'verification email' => ['verification', 'POST', '/email/verification-notification', 'unverified'],
            'account update' => ['writes', 'PATCH', '/profile', 'client'],
            'client profile update' => ['writes', 'PATCH', '/client-profile', 'client'],
            'save an offer' => ['offers-store', 'POST', '/offers', 'client'],
            'configurator catalog' => ['catalog-read', 'GET', '/offers/catalog/clients', 'admin'],
            'create a client' => ['client-create', 'POST', '/admin/clients', 'admin'],
        ];
    }

    private function actAs(string $who): void
    {
        match ($who) {
            'client' => $this->actingAs(User::factory()->client()->create()),
            'admin' => $this->actingAs(User::factory()->admin()->create()),
            'unverified' => $this->actingAs(User::factory()->client()->unverified()->create()),
            default => null,
        };
    }

    /** One request with a different login each time, so the email+IP limit of LoginRequest never interferes. */
    private function send(string $method, string $uri, int $n, array $headers = []): TestResponse
    {
        $body = $uri === '/login' ? ['email' => "user$n@example.test", 'password' => 'x'] : [];

        return $this->call($method, $uri, $body, [], [], $this->transformHeadersToServerVars($headers));
    }

    #[DataProvider('limitedRoutes')]
    public function test_the_request_after_the_limit_is_a_429(string $limiter, string $method, string $uri, string $who): void
    {
        $this->actAs($who);
        $limit = AppServiceProvider::LIMITERS[$limiter];

        for ($i = 1; $i <= $limit; $i++) {
            $this->assertNotSame(429, $this->send($method, $uri, $i, ['Accept' => 'application/json'])->status(), "Request $i of $limit must not be limited");
        }

        $this->send($method, $uri, $limit + 1, ['Accept' => 'application/json'])->assertStatus(429);
    }

    public function test_every_named_limiter_is_used_by_a_route(): void
    {
        $used = collect(app('router')->getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
            ->map(fn ($middleware) => substr($middleware, 9))
            ->unique()->values()->all();

        foreach (array_keys(AppServiceProvider::LIMITERS) as $name) {
            $this->assertContains($name, $used, "Limiter $name is defined but no route uses it");
        }

        foreach ($used as $name) {
            $this->assertArrayHasKey($name, AppServiceProvider::LIMITERS, "Route uses an unnamed throttle: $name");
        }
    }

    public function test_the_counters_are_separate_per_limiter(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Ten reads of the configurator catalog must not use up the budget of saving an offer...
        for ($i = 0; $i < AppServiceProvider::LIMITERS['catalog-read'] - 1; $i++) {
            $this->getJson('/offers/catalog/clients?q=ab')->assertOk();
        }

        for ($i = 0; $i < AppServiceProvider::LIMITERS['offers-store']; $i++) {
            $this->assertNotSame(429, $this->postJson('/offers', [])->status());
        }

        // ... and the saves do not use up the reads (the catalog budget is exactly one request away).
        $this->getJson('/offers/catalog/clients?q=ab')->assertOk();
        $this->getJson('/offers/catalog/clients?q=ab')->assertStatus(429);
        $this->postJson('/offers', [])->assertStatus(429);
    }

    public function test_a_form_goes_back_with_a_flashed_serbian_error(): void
    {
        $this->actingAs(User::factory()->client()->create());

        for ($i = 0; $i < AppServiceProvider::LIMITERS['writes']; $i++) {
            $this->patch('/profile', []);
        }

        $response = $this->from('/profile')->patch('/profile', [], ['X-Inertia' => 'true']);

        $response->assertRedirect('/profile')
            ->assertSessionHas('error', 'Previše zahteva. Pokušajte ponovo za nekoliko trenutaka.');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_a_guest_form_shows_the_error_on_the_same_page(): void
    {
        for ($i = 0; $i < AppServiceProvider::LIMITERS['auth-forgot']; $i++) {
            $this->post('/forgot-password', []);
        }

        $this->from('/forgot-password')->post('/forgot-password', [])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('error');
    }

    public function test_a_plain_get_keeps_the_standard_429_page_with_retry_after(): void
    {
        $client = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $client->id]);
        $this->actingAs($client);

        // Rendering 30 PDFs is slow: fill the counter of the "pdf" limiter directly (same key as ThrottleRequests builds).
        for ($i = 0; $i < AppServiceProvider::LIMITERS['pdf']; $i++) {
            RateLimiter::hit(md5('pdf'.$client->id), 60);
        }

        $response = $this->get("/offers/{$offer->id}/pdf");

        $response->assertStatus(429)->assertSee('Previše zahteva');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_json_gets_a_serbian_message_and_retry_after(): void
    {
        $this->actingAs(User::factory()->client()->create());

        for ($i = 0; $i < AppServiceProvider::LIMITERS['offers-store']; $i++) {
            $this->postJson('/offers', []);
        }

        $response = $this->postJson('/offers', []);

        $response->assertStatus(429)->assertExactJson(['message' => 'Previše zahteva. Pokušajte ponovo za nekoliko trenutaka.']);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_a_limited_request_does_not_leak_into_another_user(): void
    {
        $a = User::factory()->client()->create();
        $b = User::factory()->client()->create();

        $this->actingAs($a);
        for ($i = 0; $i <= AppServiceProvider::LIMITERS['offers-store']; $i++) {
            $this->postJson('/offers', []);
        }
        $this->postJson('/offers', [])->assertStatus(429);

        $this->actingAs($b)->postJson('/offers', [])->assertStatus(422);
    }
}
