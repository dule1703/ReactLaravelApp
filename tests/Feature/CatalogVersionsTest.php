<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\User;
use App\Models\Version;
use App\Support\Vat;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogVersionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function as(): static
    {
        return $this->actingAs($this->admin);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $query = ''): array
    {
        return $this->as()->get('/admin/catalog/versions'.$query)->viewData('page')['props']['items']['data'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Trim $trim, Engine $engine, Transmission $transmission, array $overrides = []): array
    {
        return array_merge([
            'trim_id' => $trim->id,
            'engine_id' => $engine->id,
            'transmission_id' => $transmission->id,
            'mode' => 'net',
            'amount' => '25.000',
        ], $overrides);
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $version = Version::factory()->create();
        $requests = [
            ['get', '/admin/catalog/versions'], ['post', '/admin/catalog/versions'],
            ['patch', "/admin/catalog/versions/{$version->id}/active"], ['delete', "/admin/catalog/versions/{$version->id}"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        $this->assertNotNull($version->fresh());
    }

    public function test_a_version_cannot_be_edited_here_only_its_status_changes(): void
    {
        $version = Version::factory()->create();

        $this->as()->patch("/admin/catalog/versions/{$version->id}", ['base_price_cents' => 1])->assertStatus(405);
    }

    // --- list ---

    public function test_the_list_shows_model_trim_engine_transmission_and_net_and_gross_price(): void
    {
        Setting::setVatRateBp(1000);
        $version = Version::factory()->create(['base_price_cents' => 2_500_000]);

        $this->as()->get('/admin/catalog/versions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Versions')
            ->where('items.data.0.id', $version->id)
            ->where('items.data.0.model_name', $version->trim->carModel->name)
            ->where('items.data.0.trim_name', $version->trim->name)
            ->where('items.data.0.engine', $version->engine->name)
            ->where('items.data.0.transmission', $version->transmission->name)
            ->where('items.data.0.net', 2_500_000)
            ->where('items.data.0.gross', Vat::grossFromNet(2_500_000, 1000))
            ->where('items.data.0.price_url', route('prices.index', ['model' => $version->trim->car_model_id]))
            ->where('items.data.0.available', true)
            ->where('vat.rate_bp', 1000)
            ->has('models')->has('trims')->has('engines')->has('transmissions'));
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $first = Version::factory()->create();
        $second = Version::factory()->create();
        $sibling = Version::factory()->create(['trim_id' => $first->trim_id]);

        $ids = fn (string $query) => array_column($this->rows($query), 'id');

        $this->assertEqualsCanonicalizing([$first->id, $second->id, $sibling->id], $ids(''));
        $this->assertEqualsCanonicalizing([$first->id, $sibling->id], $ids('?model='.$first->trim->car_model_id));
        $this->assertSame([$second->id], $ids('?trim='.$second->trim_id));
        $this->assertSame([$second->id], $ids('?q='.urlencode($second->engine->name)));
        $this->assertSame([$first->id, $sibling->id], array_values(array_intersect($ids('?q='.urlencode($first->trim->name)), [$first->id, $sibling->id])));
    }

    public function test_the_status_filter_uses_the_availability_scope(): void
    {
        $available = Version::factory()->create();
        $inactive = Version::factory()->inactive()->create();
        $unavailable = Version::factory()->create();
        $unavailable->engine->update(['is_active' => false]);

        $ids = fn (string $query) => array_column($this->rows($query), 'id');

        $this->assertEqualsCanonicalizing([$available->id, $unavailable->id], $ids('?status=active'));
        $this->assertSame([$inactive->id], $ids('?status=inactive'));
        $this->assertSame([$unavailable->id], $ids('?status=unavailable'));

        $row = collect($this->rows())->firstWhere('id', $unavailable->id);
        $this->assertFalse($row['available']);
        $this->assertSame(['engine'], $row['inactive_parents']);
        $this->assertSame(0, $row['available_versions']);

        $this->assertSame([], collect($this->rows())->firstWhere('id', $available->id)['inactive_parents']);
        $this->assertSame(1, collect($this->rows())->firstWhere('id', $available->id)['available_versions']);
    }

    public function test_every_inactive_parent_is_named(): void
    {
        $version = Version::factory()->create();
        $version->trim->carModel->update(['is_active' => false]);
        $version->trim->update(['is_active' => false]);
        $version->transmission->update(['is_active' => false]);

        $row = $this->rows()[0];

        $this->assertEqualsCanonicalizing(['car_model', 'trim', 'transmission'], $row['inactive_parents']);
    }

    public function test_the_list_runs_a_constant_number_of_queries(): void
    {
        Version::factory()->count(2)->create();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as()->get('/admin/catalog/versions')->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $count();
        Version::factory()->count(15)->create();

        $this->assertSame($few, $count());
    }

    public function test_the_list_is_paginated_only_when_needed(): void
    {
        Version::factory()->count(27)->create();

        $this->as()->get('/admin/catalog/versions')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 25)->where('items.last_page', 2)->where('items.total', 27));
        $this->assertCount(2, $this->rows('?page=2'));
    }

    // --- adding ---

    public function test_a_version_is_added_with_a_net_price(): void
    {
        [$trim, $engine, $transmission] = [Trim::factory()->create(), Engine::factory()->create(), Transmission::factory()->create()];

        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['amount' => '25.500,50']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Dodato.');

        $version = Version::sole();
        $this->assertSame(2_550_050, $version->base_price_cents);
        $this->assertTrue($version->is_active);
        $this->assertSame([$trim->id, $engine->id, $transmission->id], [$version->trim_id, $version->engine_id, $version->transmission_id]);
    }

    public function test_a_gross_price_is_converted_by_the_server_with_the_current_rate(): void
    {
        [$trim, $engine, $transmission] = [Trim::factory()->create(), Engine::factory()->create(), Transmission::factory()->create()];

        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['mode' => 'gross', 'amount' => '30.000', 'net' => 1, 'base_price_cents' => 1]));

        $this->assertSame(2_500_000, Version::sole()->base_price_cents);

        Setting::setVatRateBp(1000);
        $this->as()->post('/admin/catalog/versions', $this->payload($trim, Engine::factory()->create(), $transmission, ['mode' => 'gross', 'amount' => '27.500']));
        $this->assertSame(2_500_000, Version::orderByDesc('id')->first()->base_price_cents);
    }

    public function test_inactive_parts_can_be_chosen_and_the_version_is_then_not_offered(): void
    {
        [$trim, $engine, $transmission] = [Trim::factory()->inactive()->create(), Engine::factory()->inactive()->create(), Transmission::factory()->create()];

        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission))->assertSessionHasNoErrors();

        $this->assertSame(1, Version::count());
        $this->assertSame(0, Version::available()->count());
    }

    public function test_the_combination_must_be_unique(): void
    {
        $version = Version::factory()->create();

        $this->as()->post('/admin/catalog/versions', $this->payload($version->trim, $version->engine, $version->transmission))
            ->assertSessionHasErrors(['trim_id' => 'Ta kombinacija paketa, motora i menjača već postoji.']);

        // Another engine on the same trim is a different combination.
        $this->as()->post('/admin/catalog/versions', $this->payload($version->trim, Engine::factory()->create(), $version->transmission))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Version::count());
    }

    public function test_bad_input_is_rejected(): void
    {
        [$trim, $engine, $transmission] = [Trim::factory()->create(), Engine::factory()->create(), Transmission::factory()->create()];

        foreach (['', 'abc', '0', '-5', '25,000', '1e5', '99999999999'] as $amount) {
            $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['amount' => $amount]))
                ->assertSessionHasErrors('amount');
        }
        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['mode' => 'other']))->assertSessionHasErrors('mode');
        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['trim_id' => 9999]))->assertSessionHasErrors('trim_id');
        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['engine_id' => null]))->assertSessionHasErrors('engine_id');
        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission, ['transmission_id' => 'x']))->assertSessionHasErrors('transmission_id');

        $this->assertSame(0, Version::count());
    }

    public function test_adding_a_version_is_logged_with_its_price(): void
    {
        [$trim, $engine, $transmission] = [Trim::factory()->create(), Engine::factory()->create(), Transmission::factory()->create()];

        $this->as()->post('/admin/catalog/versions', $this->payload($trim, $engine, $transmission));

        $log = ActivityLog::where('action', 'version.created')->sole();
        $this->assertEquals(2_500_000, $log->changes['base_price_cents']['new']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    // --- status and deleting ---

    public function test_deactivating_a_version_removes_it_from_the_available_scope(): void
    {
        $version = Version::factory()->create();

        $this->as()->patch("/admin/catalog/versions/{$version->id}/active", ['is_active' => false])
            ->assertSessionHas('success', 'Status je promenjen.');
        $this->assertSame(0, Version::available()->count());

        $this->as()->patch("/admin/catalog/versions/{$version->id}/active", ['is_active' => true]);
        $this->assertSame(1, Version::available()->count());
    }

    public function test_a_version_without_dependents_can_be_deleted_and_it_is_logged(): void
    {
        $version = Version::factory()->create();

        $this->as()->delete("/admin/catalog/versions/{$version->id}")->assertSessionHas('success', 'Obrisano.');

        $this->assertNull(Version::find($version->id));
        $this->assertSame(1, ActivityLog::where('action', 'version.deleted')->where('subject_id', $version->id)->count());
        // Its trim, engine and transmission stay.
        $this->assertSame(1, CarModel::count());
        $this->assertSame(1, Engine::count());
    }

    public function test_the_blocked_message_of_a_version_uses_the_feminine_form(): void
    {
        $messages = $this->app->make(Translator::class);

        $this->assertSame(
            'Verzija ima zavisne redove (ponuda: 2). Deaktivirajte je umesto brisanja.',
            $messages->get(':entity has dependent rows (:details). Deactivate it (f.) instead of deleting.', ['entity' => 'Verzija', 'details' => 'ponuda: 2']),
        );
    }
}
