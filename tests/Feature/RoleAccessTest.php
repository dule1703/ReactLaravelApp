<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_admin_area(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_client_gets_403_on_admin_area(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_open_admin_area(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    }

    public function test_the_admin_page_links_to_the_issuer_screen_only_for_the_admin(): void
    {
        $issuer = route('issuer.edit', absolute: false);
        $source = file_get_contents(resource_path('js/Pages/Admin/Dashboard.jsx'));

        // The shortcuts are a list in the page source: the issuer card must be in it, by route name (AdminLinkCard uses it for the href).
        $this->assertStringContainsString("routeName: 'issuer.edit'", $source);
        $this->assertSame('/admin/issuer', $issuer);

        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
        $this->get($issuer)->assertOk();

        $this->actingAs(User::factory()->client()->create())->get('/admin')->assertForbidden();
        $this->get($issuer)->assertForbidden();

        auth()->logout();
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_403_page_message_is_in_serbian(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/admin')
            ->assertForbidden()
            ->assertSee('Nemate pravo pristupa ovoj stranici.');
    }

    public function test_admin_is_redirected_to_admin_area_after_login(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_client_is_redirected_to_dashboard_after_login(): void
    {
        $client = User::factory()->client()->create();

        $this->post('/login', ['email' => $client->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_user_policy_lets_a_client_see_only_their_own_data(): void
    {
        $client = User::factory()->client()->create();
        $other = User::factory()->client()->create();

        $this->assertTrue(Gate::forUser($client)->allows('view', $client));
        $this->assertTrue(Gate::forUser($client)->allows('update', $client));
        $this->assertFalse(Gate::forUser($client)->allows('view', $other));
        $this->assertFalse(Gate::forUser($client)->allows('update', $other));
        $this->assertFalse(Gate::forUser($client)->allows('delete', $other));
        $this->assertFalse(Gate::forUser($client)->allows('viewAny', User::class));
    }

    public function test_user_policy_lets_an_admin_manage_clients_but_not_delete_self(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $client));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $client));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $client));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));
    }

    public function test_role_middleware_accepts_a_list_of_roles(): void
    {
        Route::middleware(['web', 'auth', 'role:admin,client'])
            ->get('/_both', fn () => 'ok');

        $this->actingAs(User::factory()->client()->create())->get('/_both')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/_both')->assertOk();
    }
}
