<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClientProfile;
use App\Models\Offer;
use App\Models\TrimEquipment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/**
 * 6.3: EVERY route of the application as a table: guest / client / admin (and another client on
 * the routes of an offer). Nothing is listed per route except the exceptions, so a new route is
 * covered the moment it exists and is judged by the rule of its middleware:
 *
 *  - not in PUBLIC: a guest is sent to the login page;
 *  - `role:admin` (or under /admin): a client gets 403, an admin gets in;
 *  - `role:client`: an admin gets 403, a client gets in;
 *  - only `auth`: both roles get in, except the exceptions below (the Policy decides);
 *  - routes of an offer: another client always gets 404 (consecutive numbers: a 403 would tell
 *    that the offer exists).
 *
 * "Gets in" means: not 401/403/404 and not a redirect to the login page (the empty body may
 * answer 302/422/409, which is fine here). Every request runs inside a savepoint that is rolled
 * back, so a delete does not change what the next request sees.
 */
class RouteAccessMatrixTest extends TestCase
{
    use BuildsOfferCatalog;
    use RefreshDatabase;

    /**
     * The only routes a guest may open: the welcome page, the auth screens, the health check and the
     * framework's own routes (CSRF cookie; the storage route serves local files only for signed URLs).
     * Add a route here ONLY if it must be public.
     */
    private const PUBLIC = [
        'GET /', 'GET /up', 'GET /login', 'POST /login', 'GET /register', 'POST /register',
        'GET /forgot-password', 'POST /forgot-password', 'GET /reset-password/{token}', 'POST /reset-password',
        'GET /sanctum/csrf-cookie', 'GET /storage/{path}', 'PUT /storage/{path}',
    ];

    /**
     * Routes of the signed-in user's own account: no resource of someone else is reachable through
     * them (no id in the route, or a signed link), so the Policy question does not arise.
     */
    private const SIGNED_LINK = ['verification.verify'];

    /** Expected status of the OWNER and of the ADMIN on the routes of an offer (route name => [owner, admin]). */
    private const OFFER_ROUTES = [
        'offers.show' => [200, 200],
        'offers.pdf' => [200, 200],
        'offers.note.update' => [302, 302],
        'offers.withdraw' => [302, 403],
        'offers.withdrawal.revert' => [403, 302],
        'offers.destroy' => [403, 302],
        'offers.restore' => [404, 302],
    ];

    private User $owner;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        Category::factory()->create();
        $this->owner = User::factory()->client()->create();
        $this->other = User::factory()->client()->create();
        $this->admin = User::factory()->admin()->create();
        $this->owner->profile();
    }

    /** @return list<RoutingRoute> */
    private function routes(): array
    {
        return collect(Route::getRoutes()->getRoutes())->values()->all();
    }

    private function method(RoutingRoute $route): string
    {
        return collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
    }

    private function key(RoutingRoute $route): string
    {
        return $this->method($route).' /'.ltrim($route->uri(), '/');
    }

    private function isAdminOnly(RoutingRoute $route): bool
    {
        return in_array('role:admin', $route->gatherMiddleware(), true);
    }

    private function isClientOnly(RoutingRoute $route): bool
    {
        return in_array('role:client', $route->gatherMiddleware(), true);
    }

    /** Real rows for every route parameter, so a 404 never comes from model binding. */
    private function uri(RoutingRoute $route, ?Offer $offer = null): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($route, $offer) {
            return (string) match ($m[1]) {
                'offer' => $offer?->id ?? Offer::factory()->create(['user_id' => $this->owner->id])->id,
                'carModel' => $this->version->trim->car_model_id,
                'version' => $this->version->id,
                'trim' => $this->version->trim_id,
                'engine' => $this->version->engine_id,
                'transmission' => $this->version->transmission_id,
                'category' => Category::query()->value('id'),
                'equipmentItem' => $this->climatronic->id,
                'optionGroup' => $this->metallic->group_id,
                'trimEquipment' => TrimEquipment::query()->value('id'),
                'clientProfile' => ClientProfile::query()->where('user_id', $this->owner->id)->value('id'),
                'token', 'path' => 'x',
                'id' => $this->owner->id,
                'hash' => sha1('x'),
                default => throw new \LogicException('Route '.$this->key($route)." has the parameter {{$m[1]}}: add real rows for it in RouteAccessMatrixTest::uri()."),
            };
        }, '/'.ltrim($route->uri(), '/'));
    }

    /** One request as $user (null = guest), rolled back afterwards. */
    private function visit(?User $user, RoutingRoute $route, ?Offer $offer = null, bool $trashed = false)
    {
        DB::beginTransaction();

        try {
            Cache::flush(); // the rate limiters live in the cache: one request per case must never be limited
            $this->flushSession();
            $user ? $this->actingAs($user) : auth()->forgetUser();

            $offer ??= null;
            if ($trashed) {
                $offer = Offer::factory()->create(['user_id' => $this->owner->id]);
                $offer->delete();
            }

            return $this->call($this->method($route), $this->uri($route, $offer));
        } finally {
            DB::rollBack();
        }
    }

    private function assertGetsIn($response, string $who, string $key): void
    {
        $this->assertNotContains($response->getStatusCode(), [401, 403, 404], "$who must get in on $key, got ".$response->getStatusCode());

        if ($response->isRedirection()) {
            $this->assertStringNotContainsString('/login', (string) $response->headers->get('Location'), "$who was sent to the login page on $key");
        }
    }

    public function test_a_guest_is_sent_to_the_login_page_everywhere_except_the_public_routes(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            $key = $this->key($route);

            if (in_array($key, self::PUBLIC, true)) {
                continue;
            }

            $response = $this->visit(null, $route);

            $this->assertTrue($response->isRedirection(), "A guest must be redirected on $key, got ".$response->getStatusCode());
            $this->assertStringContainsString('/login', (string) $response->headers->get('Location'), "A guest must be sent to the login page on $key");
            $checked++;
        }

        $this->assertGreaterThan(40, $checked, 'The route table looks too small: is the application booted?');
    }

    public function test_the_public_list_has_no_stale_entries(): void
    {
        $existing = array_map(fn ($route) => $this->key($route), $this->routes());

        foreach (self::PUBLIC as $key) {
            $this->assertContains($key, $existing, "PUBLIC lists a route that no longer exists: $key");
        }
    }

    public function test_admin_routes_are_closed_to_a_client_and_open_to_an_admin(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            $key = $this->key($route);
            $adminOnly = $this->isAdminOnly($route) || str_starts_with($route->uri(), 'admin');

            // The Policy of the catalog JSON for admins (offers.catalog.clients) is checked here as well.
            if (! $adminOnly && $route->getName() !== 'offers.catalog.clients') {
                continue;
            }

            $this->assertSame(403, $this->visit($this->owner, $route)->getStatusCode(), "A client must get 403 on $key");
            $this->assertGetsIn($this->visit($this->admin, $route), 'The admin', $key);
            $checked++;
        }

        $this->assertGreaterThan(40, $checked);
    }

    public function test_every_admin_path_is_behind_the_admin_role(): void
    {
        foreach ($this->routes() as $route) {
            if (str_starts_with($route->uri(), 'admin')) {
                $this->assertTrue($this->isAdminOnly($route), $this->key($route).' is under /admin but has no role:admin middleware');
            }
        }
    }

    public function test_client_routes_are_closed_to_an_admin_and_open_to_a_client(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            if (! $this->isClientOnly($route)) {
                continue;
            }

            $key = $this->key($route);
            $this->assertSame(403, $this->visit($this->admin, $route)->getStatusCode(), "An admin must get 403 on $key");
            $this->assertGetsIn($this->visit($this->owner, $route), 'The client', $key);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_the_remaining_routes_are_open_to_both_roles(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            $key = $this->key($route);
            $name = $route->getName();

            if (in_array($key, self::PUBLIC, true) || $this->isAdminOnly($route) || $this->isClientOnly($route)
                || str_starts_with($route->uri(), 'admin') || $name === 'offers.catalog.clients'
                || isset(self::OFFER_ROUTES[$name]) || in_array($name, self::SIGNED_LINK, true)) {
                continue;
            }

            foreach (['client' => $this->owner, 'admin' => $this->admin] as $who => $user) {
                $this->assertGetsIn($this->visit($user, $route), "The $who", $key);
            }
            $checked++;
        }

        $this->assertGreaterThan(8, $checked);
    }

    public function test_a_signed_link_route_refuses_an_unsigned_request_but_never_the_login_redirect(): void
    {
        foreach ($this->routes() as $route) {
            if (in_array($route->getName(), self::SIGNED_LINK, true)) {
                $this->assertSame(403, $this->visit($this->owner, $route)->getStatusCode());
            }
        }
    }

    public function test_the_routes_of_an_offer_belong_to_the_owner_and_the_admin_only(): void
    {
        $seen = [];

        foreach ($this->routes() as $route) {
            $name = $route->getName();

            if (! isset(self::OFFER_ROUTES[$name])) {
                continue;
            }

            [$owner, $admin] = self::OFFER_ROUTES[$name];
            $restore = $name === 'offers.restore';

            // Another client: 404 on every route of an offer, never 403.
            $this->assertSame(404, $this->visit($this->other, $route)->getStatusCode(), "Another client must get 404 on $name");

            $this->assertSame($owner, $this->visit($this->owner, $route)->getStatusCode(), "The owner on $name");
            $this->assertSame($admin, $this->visit($this->admin, $route, null, $restore)->getStatusCode(), "The admin on $name");
            $seen[] = $name;
        }

        $this->assertEqualsCanonicalizing(array_keys(self::OFFER_ROUTES), $seen, 'Every route of an offer must be in OFFER_ROUTES');
    }

    public function test_every_route_with_an_offer_parameter_is_in_the_offer_table(): void
    {
        foreach ($this->routes() as $route) {
            if (in_array('offer', $route->parameterNames(), true)) {
                $this->assertArrayHasKey($route->getName(), self::OFFER_ROUTES, $this->key($route).' has an {offer} parameter: add it to OFFER_ROUTES');
            }
        }
    }

    /**
     * authorize() of a Form Request must ask the Policy. A bare `return true` is allowed only for the
     * Requests of the public routes (login, register); a Request without authorize() at all would
     * be open as well, so it must inherit one from an abstract parent.
     */
    public function test_only_the_public_form_requests_authorize_with_a_bare_true(): void
    {
        $bare = [];

        foreach ((new Finder)->files()->in(app_path('Http/Requests'))->name('*.php') as $file) {
            $class = 'App\\Http\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(FormRequest::class)) {
                continue;
            }

            $method = $reflection->getMethod('authorize');
            $this->assertNotSame(FormRequest::class, $method->getDeclaringClass()->getName(), "{$reflection->getShortName()} has no authorize(): it would be open to everyone");

            $source = implode("\n", array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            if (preg_match('/return\s+true\s*;/', $source)) {
                $bare[] = $reflection->getShortName();
            }
        }

        sort($bare);
        $this->assertSame(['LoginRequest', 'RegisterRequest'], $bare, 'Only the Requests of the public routes may authorize with a bare true');
    }
}
