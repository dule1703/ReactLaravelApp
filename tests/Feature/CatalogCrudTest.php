<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogCrudTest extends TestCase
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

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $model = CarModel::factory()->create();
        $trim = Trim::factory()->create();
        $engine = Engine::factory()->create();
        $transmission = Transmission::factory()->create();

        $requests = [
            ['get', '/admin/catalog/models'], ['post', '/admin/catalog/models'],
            ['patch', "/admin/catalog/models/{$model->id}"], ['patch', "/admin/catalog/models/{$model->id}/active"], ['delete', "/admin/catalog/models/{$model->id}"],
            ['get', '/admin/catalog/trims'], ['post', '/admin/catalog/trims'],
            ['patch', "/admin/catalog/trims/{$trim->id}"], ['patch', "/admin/catalog/trims/{$trim->id}/active"], ['delete', "/admin/catalog/trims/{$trim->id}"],
            ['get', '/admin/catalog/engines'], ['post', '/admin/catalog/engines'],
            ['patch', "/admin/catalog/engines/{$engine->id}"], ['patch', "/admin/catalog/engines/{$engine->id}/active"], ['delete', "/admin/catalog/engines/{$engine->id}"],
            ['get', '/admin/catalog/transmissions'], ['post', '/admin/catalog/transmissions'],
            ['patch', "/admin/catalog/transmissions/{$transmission->id}"], ['patch', "/admin/catalog/transmissions/{$transmission->id}/active"], ['delete', "/admin/catalog/transmissions/{$transmission->id}"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        // Nothing was created or removed by the forbidden requests.
        $this->assertNotNull($model->fresh());
        $this->assertNotNull($trim->fresh());
        $this->assertSame(2, CarModel::count());
    }

    public function test_the_catalog_root_redirects_to_models(): void
    {
        $this->as()->get('/admin/catalog')->assertRedirect('/admin/catalog/models');
    }

    // --- lists ---

    public function test_lists_show_rows_with_counts_and_search_by_name(): void
    {
        $version = Version::factory()->create();
        CarModel::factory()->create(['name' => 'Fabia']);

        $this->as()->get('/admin/catalog/models')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Models')
            ->has('items.data', 2)
            ->where('items.last_page', 1));

        $this->as()->get('/admin/catalog/models?q=fab')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)->where('items.data.0.name', 'Fabia')->where('filters.q', 'fab'));

        $this->as()->get('/admin/catalog/trims')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Trims')
            ->where('items.data.0.model_name', $version->trim->carModel->name)
            ->where('items.data.0.versions_count', 1)
            ->has('models'));
        $this->as()->get('/admin/catalog/engines')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Engines')->where('items.data.0.versions_count', 1)->has('fuelTypes', 5));
        $this->as()->get('/admin/catalog/transmissions')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Transmissions')->where('items.data.0.versions_count', 1)->has('types', 2)->has('drives', 3));
    }

    public function test_search_wildcards_are_escaped(): void
    {
        CarModel::factory()->create(['name' => 'Model 50%']);
        CarModel::factory()->create(['name' => 'Model_A']);
        CarModel::factory()->create(['name' => 'Obican']);

        $count = fn (string $q) => $this->as()->get('/admin/catalog/models?'.http_build_query(['q' => $q]))
            ->viewData('page')['props']['items']['data'];

        $this->assertSame(['Model 50%'], array_column($count('%'), 'name'));
        $this->assertSame(['Model_A'], array_column($count('_'), 'name'));
        $this->assertSame([], $count('\\'));
    }

    public function test_the_list_is_paginated_only_when_needed(): void
    {
        CarModel::factory()->count(30)->create();

        $this->as()->get('/admin/catalog/models')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 25)->where('items.last_page', 2)->where('items.total', 30));
        $this->as()->get('/admin/catalog/models?page=2')->assertInertia(fn (Assert $page) => $page->has('items.data', 5));
    }

    // --- models ---

    public function test_a_model_is_created_with_a_generated_slug_and_the_next_order(): void
    {
        CarModel::factory()->create(['sort_order' => 7]);

        $this->as()->post('/admin/catalog/models', ['name' => 'Škoda Octavia RS'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Dodato.');

        $model = CarModel::where('name', 'Škoda Octavia RS')->sole();
        $this->assertSame('skoda-octavia-rs', $model->slug);
        $this->assertTrue($model->is_active);
        $this->assertSame(8, $model->sort_order);
    }

    public function test_slugs_are_unique_and_never_change(): void
    {
        $this->as()->post('/admin/catalog/models', ['name' => 'A B']);
        $this->as()->post('/admin/catalog/models', ['name' => 'A-B']);
        $this->as()->post('/admin/catalog/models', ['name' => 'A  B']);

        $this->assertEqualsCanonicalizing(['a-b', 'a-b-2', 'a-b-3'], CarModel::pluck('slug')->all());

        $model = CarModel::where('slug', 'a-b')->sole();
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'Potpuno drugo ime', 'slug' => 'hacked']);

        $this->assertSame('a-b', $model->fresh()->slug);
        $this->assertSame('Potpuno drugo ime', $model->fresh()->name);
    }

    public function test_model_names_are_unique_without_regard_to_case(): void
    {
        CarModel::factory()->create(['name' => 'Octavia']);
        CarModel::factory()->create(['name' => 'Škoda']);

        foreach (['Octavia', 'octavia', 'OCTAVIA', ' octavia ', 'ŠKODA'] as $name) {
            $this->as()->post('/admin/catalog/models', ['name' => $name])
                ->assertSessionHasErrors(['name' => 'Model sa tim nazivom već postoji.']);
        }

        $this->assertSame(2, CarModel::count());

        // A model can be saved with its own name (even in another case).
        $model = CarModel::where('name', 'Octavia')->sole();
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'OCTAVIA'])->assertSessionHasNoErrors();
        $this->assertSame('OCTAVIA', $model->fresh()->name);
    }

    public function test_a_model_is_updated_and_the_change_is_logged_with_old_and_new_value(): void
    {
        $model = CarModel::factory()->create(['name' => 'Fabia', 'sort_order' => 3]);

        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'Fabia RS', 'sort_order' => '9'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Sačuvano.');

        $this->assertSame(9, $model->fresh()->sort_order);

        $log = ActivityLog::where('action', 'car_model.updated')->where('subject_id', $model->id)->sole();
        $this->assertEquals(['old' => 'Fabia', 'new' => 'Fabia RS'], $log->changes['name']);
        $this->assertEquals(['old' => 3, 'new' => 9], $log->changes['sort_order']);

        // An empty order keeps the current one.
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'Fabia RS', 'sort_order' => '']);
        $this->assertSame(9, $model->fresh()->sort_order);
    }

    public function test_model_input_is_validated(): void
    {
        foreach ([
            ['name' => ''],
            ['name' => str_repeat('a', 101)],
            ['name' => "Bad\x00name"],
            ['name' => 'Ok', 'sort_order' => '-1'],
            ['name' => 'Ok', 'sort_order' => '65536'],
            ['name' => 'Ok', 'sort_order' => 'abc'],
        ] as $payload) {
            $this->as()->post('/admin/catalog/models', $payload)->assertSessionHasErrors();
        }

        $this->assertSame(0, CarModel::count());
    }

    // --- trims ---

    public function test_a_trim_is_created_for_a_model_and_unique_per_model_without_regard_to_case(): void
    {
        $model = CarModel::factory()->create();
        $other = CarModel::factory()->create();
        Trim::factory()->for($model)->create(['sort_order' => 4]);

        $this->as()->post('/admin/catalog/trims', ['car_model_id' => $model->id, 'name' => 'Style'])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, Trim::where('name', 'Style')->sole()->sort_order);

        foreach (['Style', 'style', 'STYLE'] as $name) {
            $this->as()->post('/admin/catalog/trims', ['car_model_id' => $model->id, 'name' => $name])
                ->assertSessionHasErrors(['name' => 'Ovaj model već ima paket sa tim nazivom.']);
        }

        // The same name on another model is fine.
        $this->as()->post('/admin/catalog/trims', ['car_model_id' => $other->id, 'name' => 'Style'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Trim::where('name', 'Style')->count());

        $this->as()->post('/admin/catalog/trims', ['name' => 'No model'])->assertSessionHasErrors('car_model_id');
        $this->as()->post('/admin/catalog/trims', ['car_model_id' => 9999, 'name' => 'Ghost'])->assertSessionHasErrors('car_model_id');
    }

    public function test_the_model_of_a_trim_cannot_be_changed(): void
    {
        $model = CarModel::factory()->create();
        $other = CarModel::factory()->create();
        $trim = Trim::factory()->for($model)->create(['name' => 'Style']);

        $this->as()->patch("/admin/catalog/trims/{$trim->id}", ['name' => 'Style', 'car_model_id' => $other->id, 'sort_order' => '3'])
            ->assertSessionHasNoErrors();

        $this->assertSame($model->id, $trim->fresh()->car_model_id);
        $this->assertSame(3, $trim->fresh()->sort_order);
    }

    public function test_a_trim_can_be_renamed_but_not_to_a_sibling_name(): void
    {
        $model = CarModel::factory()->create();
        $style = Trim::factory()->for($model)->create(['name' => 'Style']);
        Trim::factory()->for($model)->create(['name' => 'Ambition']);

        $this->as()->patch("/admin/catalog/trims/{$style->id}", ['name' => 'ambition'])->assertSessionHasErrors('name');
        $this->as()->patch("/admin/catalog/trims/{$style->id}", ['name' => 'STYLE'])->assertSessionHasNoErrors();
        $this->assertSame('STYLE', $style->fresh()->name);
    }

    // --- engines ---

    public function test_an_engine_is_created_and_unique_by_name_fuel_and_power_without_regard_to_case(): void
    {
        $payload = ['name' => '2.0 TDI', 'fuel_type' => 'diesel', 'power_kw' => '110'];

        $this->as()->post('/admin/catalog/engines', $payload)->assertSessionHasNoErrors();
        $engine = Engine::sole();
        $this->assertSame(110, $engine->power_kw);
        $this->assertTrue($engine->is_active);

        foreach (['2.0 TDI', '2.0 tdi'] as $name) {
            $this->as()->post('/admin/catalog/engines', [...$payload, 'name' => $name])
                ->assertSessionHasErrors(['name' => 'Motor sa tim nazivom, gorivom i snagom već postoji.']);
        }

        // Another power or another fuel is a different engine.
        $this->as()->post('/admin/catalog/engines', [...$payload, 'power_kw' => '147'])->assertSessionHasNoErrors();
        $this->as()->post('/admin/catalog/engines', [...$payload, 'fuel_type' => 'hybrid'])->assertSessionHasNoErrors();
        $this->assertSame(3, Engine::count());

        // Saving an engine with its own values is fine.
        $this->as()->patch("/admin/catalog/engines/{$engine->id}", $payload)->assertSessionHasNoErrors();
    }

    public function test_engine_power_and_fuel_are_validated(): void
    {
        foreach (['19', '1001', '0', '-5', 'abc', '110.5', ''] as $kw) {
            $this->as()->post('/admin/catalog/engines', ['name' => 'X', 'fuel_type' => 'petrol', 'power_kw' => $kw])
                ->assertSessionHasErrors('power_kw');
        }
        foreach (['20', '1000'] as $kw) {
            $this->as()->post('/admin/catalog/engines', ['name' => "E $kw", 'fuel_type' => 'petrol', 'power_kw' => $kw])
                ->assertSessionHasNoErrors();
        }
        foreach (['', 'steam', 'DIESEL'] as $fuel) {
            $this->as()->post('/admin/catalog/engines', ['name' => 'Y', 'fuel_type' => $fuel, 'power_kw' => '100'])
                ->assertSessionHasErrors('fuel_type');
        }
    }

    public function test_an_engine_is_updated_and_logged(): void
    {
        $engine = Engine::factory()->create(['name' => '1.0 TSI', 'fuel_type' => 'petrol', 'power_kw' => 70]);

        $this->as()->patch("/admin/catalog/engines/{$engine->id}", ['name' => '1.0 TSI', 'fuel_type' => 'petrol', 'power_kw' => '85'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'engine.updated')->where('subject_id', $engine->id)->sole();
        $this->assertEquals(['old' => 70, 'new' => 85], $log->changes['power_kw']);
    }

    // --- transmissions ---

    public function test_a_transmission_is_created_and_unique_by_name_type_and_drive_without_regard_to_case(): void
    {
        $payload = ['name' => 'DSG 7', 'type' => 'automatic', 'drive' => 'fwd'];

        $this->as()->post('/admin/catalog/transmissions', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Transmission::count());

        foreach (['DSG 7', 'dsg 7'] as $name) {
            $this->as()->post('/admin/catalog/transmissions', [...$payload, 'name' => $name])
                ->assertSessionHasErrors(['name' => 'Menjač sa tim nazivom, tipom i pogonom već postoji.']);
        }

        $this->as()->post('/admin/catalog/transmissions', [...$payload, 'drive' => 'awd'])->assertSessionHasNoErrors();
        $this->as()->post('/admin/catalog/transmissions', [...$payload, 'drive' => 'rwd'])->assertSessionHasNoErrors();
        $this->assertSame(3, Transmission::count());

        foreach ([['type' => 'cvt'], ['drive' => '6x6'], ['type' => ''], ['drive' => '']] as $bad) {
            $this->as()->post('/admin/catalog/transmissions', [...$payload, 'name' => 'Z', ...$bad])->assertSessionHasErrors();
        }
    }

    public function test_a_transmission_is_updated(): void
    {
        $transmission = Transmission::factory()->create(['name' => 'DSG 7', 'type' => 'automatic', 'drive' => 'fwd']);

        $this->as()->patch("/admin/catalog/transmissions/{$transmission->id}", ['name' => 'DSG 7 4x4', 'type' => 'automatic', 'drive' => 'awd'])
            ->assertSessionHasNoErrors();

        $this->assertSame('DSG 7 4x4', $transmission->fresh()->name);
        $this->assertSame('awd', $transmission->fresh()->drive->value);
    }

    // --- active / availability ---

    public function test_deactivating_a_parent_removes_its_versions_from_the_available_scope(): void
    {
        $version = Version::factory()->create();
        $routes = [
            "/admin/catalog/models/{$version->trim->car_model_id}/active",
            "/admin/catalog/trims/{$version->trim_id}/active",
            "/admin/catalog/engines/{$version->engine_id}/active",
            "/admin/catalog/transmissions/{$version->transmission_id}/active",
        ];

        foreach ($routes as $url) {
            $this->assertSame(1, Version::available()->count(), $url);

            $this->as()->patch($url, ['is_active' => false])->assertSessionHas('success', 'Status je promenjen.');
            $this->assertSame(0, Version::available()->count(), "after deactivating $url");

            $this->as()->patch($url, ['is_active' => true]);
            $this->assertSame(1, Version::available()->count(), "after reactivating $url");
        }

        $this->assertNotNull($version->fresh());
    }

    public function test_the_active_flag_is_validated_and_logged(): void
    {
        $model = CarModel::factory()->create();

        $this->as()->patch("/admin/catalog/models/{$model->id}/active", [])->assertSessionHasErrors('is_active');
        $this->as()->patch("/admin/catalog/models/{$model->id}/active", ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');

        $this->as()->patch("/admin/catalog/models/{$model->id}/active", ['is_active' => false]);
        $log = ActivityLog::where('action', 'car_model.updated')->where('subject_id', $model->id)->sole();
        $this->assertEquals(['old' => 1, 'new' => 0], $log->changes['is_active']);
    }

    public function test_the_lists_count_the_versions_that_would_become_unavailable(): void
    {
        $version = Version::factory()->create();
        Version::factory()->create(['trim_id' => $version->trim_id]);
        Version::factory()->inactive()->create(['trim_id' => $version->trim_id]);

        $first = fn (string $path) => $this->as()->get("/admin/catalog/$path")->viewData('page')['props']['items']['data'][0];

        // Two versions of the trim are offered now; the inactive one is not counted.
        $this->assertSame(2, $first('trims')['available_versions']);
        $this->assertSame(3, $first('trims')['versions_count']);
        $this->assertSame(2, $first('models')['available_versions']);
        $this->assertSame(3, $first('models')['versions_count']);
        $this->assertSame(1, $first('models')['trims_count']);

        $engines = $this->as()->get('/admin/catalog/engines')->viewData('page')['props']['items']['data'];
        $this->assertSame([1, 1, 0], collect($engines)->pluck('available_versions')->sortDesc()->values()->all());

        // Once the model is inactive nothing is offered, and its active trim is flagged.
        $version->trim->carModel->update(['is_active' => false]);
        $this->assertSame(0, $first('trims')['available_versions']);
        $this->assertSame('model_inactive', $first('trims')['unavailable_reason']);

        $version->trim->update(['is_active' => false]);
        $this->assertNull($first('trims')['unavailable_reason']);
    }

    // --- deleting ---

    public function test_a_model_with_trims_cannot_be_deleted_and_the_message_has_the_counts(): void
    {
        $version = Version::factory()->create();
        $model = $version->trim->carModel;

        $this->as()->delete("/admin/catalog/models/{$model->id}")
            ->assertSessionHas('error', 'Model ima zavisne redove (paketa: 1, verzija: 1). Deaktivirajte ga umesto brisanja.');

        $this->assertNotNull($model->fresh());
    }

    public function test_a_trim_with_versions_or_equipment_cannot_be_deleted(): void
    {
        $version = Version::factory()->create();
        $this->as()->delete("/admin/catalog/trims/{$version->trim_id}")
            ->assertSessionHas('error', 'Paket ima zavisne redove (verzija: 1). Deaktivirajte ga umesto brisanja.');

        $row = TrimEquipment::factory()->create();
        $this->as()->delete("/admin/catalog/trims/{$row->trim_id}")
            ->assertSessionHas('error', 'Paket ima zavisne redove (veza opreme: 1). Deaktivirajte ga umesto brisanja.');

        $this->assertSame(2, Trim::count());
    }

    public function test_an_engine_or_transmission_with_versions_cannot_be_deleted(): void
    {
        $version = Version::factory()->create();

        $this->as()->delete("/admin/catalog/engines/{$version->engine_id}")
            ->assertSessionHas('error', 'Motor ima zavisne redove (verzija: 1). Deaktivirajte ga umesto brisanja.');
        $this->as()->delete("/admin/catalog/transmissions/{$version->transmission_id}")
            ->assertSessionHas('error', 'Menjač ima zavisne redove (verzija: 1). Deaktivirajte ga umesto brisanja.');

        $this->assertNotNull($version->engine->fresh());
        $this->assertNotNull($version->transmission->fresh());
    }

    public function test_rows_without_dependents_can_be_deleted_and_it_is_logged(): void
    {
        $model = CarModel::factory()->create();
        $trim = Trim::factory()->create();
        $engine = Engine::factory()->create();
        $transmission = Transmission::factory()->create();

        $this->as()->delete("/admin/catalog/models/{$model->id}")->assertSessionHas('success', 'Obrisano.');
        $this->as()->delete("/admin/catalog/trims/{$trim->id}")->assertSessionHas('success', 'Obrisano.');
        $this->as()->delete("/admin/catalog/engines/{$engine->id}")->assertSessionHas('success', 'Obrisano.');
        $this->as()->delete("/admin/catalog/transmissions/{$transmission->id}")->assertSessionHas('success', 'Obrisano.');

        $this->assertNull(CarModel::find($model->id));
        $this->assertNull(Trim::find($trim->id));
        $this->assertNull(Engine::find($engine->id));
        $this->assertNull(Transmission::find($transmission->id));
        $this->assertSame(1, ActivityLog::where('action', 'car_model.deleted')->where('subject_id', $model->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'engine.deleted')->where('subject_id', $engine->id)->count());
    }

    public function test_a_race_that_adds_a_dependent_row_is_caught_by_the_foreign_key(): void
    {
        $model = CarModel::factory()->create();

        // After the check passed, another request adds a trim before the row is deleted.
        CarModel::deleting(fn (CarModel $row) => Trim::factory()->for($row)->create());

        $this->as()->delete("/admin/catalog/models/{$model->id}")
            ->assertSessionHas('error', 'Model ima zavisne redove. Deaktivirajte ga umesto brisanja.');

        $this->assertNotNull($model->fresh());
    }

    // --- translations ---

    public function test_the_new_labels_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['transmission.type.manual', 'transmission.type.automatic', 'catalog.entity.car_model', 'dependency.versions', 'drive.rwd', 'fuel.phev'] as $key) {
            $this->assertNotEmpty($translations[$key] ?? null, $key);
        }
    }
}
