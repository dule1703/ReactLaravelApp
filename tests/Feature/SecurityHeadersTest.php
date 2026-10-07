<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 6.3: the hardening headers are on every kind of response: a page, JSON, a redirect, a PDF, an
 * error page, a 429 and the health check. No CSP and no HSTS (decided for a later phase).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertHardened(TestResponse $response): void
    {
        foreach (SecurityHeaders::HEADERS as $name => $value) {
            $this->assertSame($value, $response->headers->get($name), "$name is missing or wrong (status {$response->getStatusCode()})");
        }

        $this->assertFalse($response->headers->has('Content-Security-Policy'), 'No CSP in this version');
        $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'No HSTS in this version');
    }

    public function test_the_exact_values(): void
    {
        $this->assertSame([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ], SecurityHeaders::HEADERS);
    }

    public function test_a_public_page_a_redirect_and_the_health_check(): void
    {
        $this->assertHardened($this->get('/login'));
        $this->assertHardened($this->get('/admin')); // guest: redirect to the login page
        $this->assertHardened($this->get('/up'));
    }

    public function test_a_json_response_a_403_and_a_404(): void
    {
        $client = User::factory()->client()->create();
        $this->actingAs($client);

        $this->assertHardened($this->getJson('/offers/catalog/clients'));
        $this->assertHardened($this->get('/admin'));
        $this->assertHardened($this->get('/no-such-page'));
    }

    public function test_the_pdf_keeps_its_type_and_is_hardened(): void
    {
        $client = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $client->id]);

        $response = $this->actingAs($client)->get("/offers/{$offer->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertHardened($response);
    }

    public function test_a_429_is_hardened_too(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', []);
        }

        $this->assertHardened($this->postJson('/forgot-password', []));
    }
}
