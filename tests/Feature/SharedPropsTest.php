<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 6.3: the props that EVERY page receives (HandleInertiaRequests::share) are locked to an exact list
 * of keys for a guest, a client and an admin. A new shared prop (or a new column of the user) fails
 * here until somebody decides that every page may carry it.
 */
class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    /** Props added by Inertia and Ziggy-free page logic itself, not by share(): errors, plus the guest-only data of the page. */
    private const SHARED = ['auth', 'nav', 'flash', 'errors'];

    /** @return array<string, mixed> */
    private function shared(?User $user, string $uri): array
    {
        $response = ($user ? $this->actingAs($user) : $this)->get($uri)->assertOk();
        $props = $response->viewData('page')['props'];

        return array_intersect_key($props, array_flip(self::SHARED));
    }

    public function test_guest_pages_share_no_user_and_no_navigation(): void
    {
        $shared = $this->shared(null, '/login');

        $this->assertEqualsCanonicalizing(['auth', 'nav', 'flash', 'errors'], array_keys($shared));
        $this->assertSame(['user' => null], $shared['auth']);
        $this->assertSame([], $shared['nav']);
        $this->assertSame(['success' => null, 'error' => null], $shared['flash']);
    }

    public function test_a_client_gets_only_the_account_fields_the_pages_read(): void
    {
        $client = User::factory()->client()->create();
        $client->profile()->update(['address' => 'Tajna ulica 1', 'city' => 'Beograd', 'pib' => '123456789']);
        Offer::factory()->create(['user_id' => $client->id]);

        $shared = $this->shared($client, '/profile');

        $this->assertEqualsCanonicalizing(['auth', 'nav', 'flash', 'errors'], array_keys($shared));
        $this->assertSame(['name', 'email', 'email_verified_at'], array_keys($shared['auth']['user']));

        $json = json_encode($shared);
        foreach (['jmbg', 'pib', 'Tajna ulica', 'password', 'remember_token', '"role"', 'address'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_an_admin_gets_the_same_account_fields_and_the_admin_navigation(): void
    {
        $admin = User::factory()->admin()->create();

        $shared = $this->shared($admin, '/profile');

        $this->assertSame(['name', 'email', 'email_verified_at'], array_keys($shared['auth']['user']));
        $this->assertSame(
            ['admin', 'activity-log', 'clients', 'catalog', 'prices', 'new-offer', 'offers'],
            array_column($shared['nav'], 'key'),
        );
        $this->assertStringNotContainsString('"role"', json_encode($shared));
    }

    public function test_the_shared_props_are_the_same_on_a_page_of_the_admin_area(): void
    {
        $shared = $this->shared(User::factory()->admin()->create(), '/admin');

        $this->assertEqualsCanonicalizing(['auth', 'nav', 'flash', 'errors'], array_keys($shared));
    }

    public function test_an_inertia_page_has_no_other_shared_key_than_the_locked_ones(): void
    {
        $this->actingAs(User::factory()->client()->create())->get('/profile')->assertInertia(
            fn (Assert $page) => $page->has('auth')->has('nav')->has('flash')->has('errors'),
        );

        // Page props of /profile itself (not shared): the locked list plus these only.
        $props = array_keys($this->actingAs(User::factory()->client()->create())->get('/profile')->viewData('page')['props']);
        $this->assertEqualsCanonicalizing(['auth', 'nav', 'flash', 'errors', 'mustVerifyEmail', 'status'], $props);
    }
}
