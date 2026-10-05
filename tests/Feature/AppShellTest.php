<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navFor(User $user): array
    {
        $nav = null;

        $this->actingAs($user)->get('/profile')->assertInertia(function (Assert $page) use (&$nav) {
            $nav = $page->toArray()['props']['nav'];
        });

        return $nav;
    }

    public function test_client_navigation(): void
    {
        $nav = $this->navFor(User::factory()->client()->create());

        $this->assertSame(['dashboard', 'profile', 'new-offer', 'offers'], array_column($nav, 'key'));
        $this->assertSame(['Početna', 'Moj profil', 'Nova ponuda', 'Ponude'], array_column($nav, 'label'));
        $this->assertSame(['/dashboard', '/client-profile', '/offers/new', null], array_column($nav, 'href'));
        $this->assertSame([false, false, false, true], array_column($nav, 'soon'));
    }

    public function test_admin_navigation(): void
    {
        $nav = $this->navFor(User::factory()->admin()->create());

        $this->assertSame(['admin', 'activity-log', 'clients', 'catalog', 'prices', 'offers'], array_column($nav, 'key'));
        $this->assertSame(['Administracija', 'Dnevnik aktivnosti', 'Klijenti', 'Katalog', 'Cene', 'Ponude'], array_column($nav, 'label'));
        $this->assertSame(['/admin', '/admin/activity-log', '/admin/clients', '/admin/catalog/models', '/admin/prices', null], array_column($nav, 'href'));
        $this->assertSame([false, false, false, false, false, true], array_column($nav, 'soon'));
    }

    public function test_clients_do_not_get_admin_links(): void
    {
        $nav = $this->navFor(User::factory()->client()->create());

        $this->assertNotContains('admin', array_column($nav, 'key'));
        $this->assertNotContains('activity-log', array_column($nav, 'key'));
        $this->assertNotContains('clients', array_column($nav, 'key'));
    }

    public function test_a_soon_item_activates_once_its_route_exists(): void
    {
        Route::get('/_offers', fn () => 'ok')->name('offers.index');
        app('router')->getRoutes()->refreshNameLookups();

        $nav = $this->navFor(User::factory()->client()->create());

        $offers = collect($nav)->firstWhere('key', 'offers');
        $this->assertFalse($offers['soon']);
        $this->assertSame('/_offers', $offers['href']);
    }

    public function test_guest_pages_share_no_navigation(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('nav', []));
    }

    public function test_flash_is_shared_once_and_then_gone(): void
    {
        $user = User::factory()->create(['name' => 'Old', 'email' => 'flash@example.com']);

        $this->actingAs($user)
            ->followingRedirects()
            ->patch('/profile', ['name' => 'New', 'email' => 'flash@example.com'])
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('flash.success', 'Profil je sačuvan.')
                ->where('flash.error', null));

        $this->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null));
    }

    public function test_password_change_sets_a_success_flash(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'a-brand-new-pass-1',
                'password_confirmation' => 'a-brand-new-pass-1',
            ])
            ->assertSessionHas('success', 'Lozinka je promenjena.');
    }

    public function test_error_flash_is_shared_too(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['error' => 'Something went wrong'])
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->where('flash.error', 'Something went wrong'));
    }

    public function test_guests_still_see_no_flash(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null));
    }
}
