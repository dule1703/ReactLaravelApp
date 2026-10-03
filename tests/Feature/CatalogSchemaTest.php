<?php

namespace Tests\Feature;

use App\Enums\DriveType;
use App\Enums\FuelType;
use App\Enums\TransmissionType;
use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\User;
use App\Models\Version;
use App\Policies\AdminOnlyPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['car_models', 'trims', 'engines', 'transmissions', 'versions'];

    // --- relations ---

    public function test_relations_work_in_both_directions(): void
    {
        $version = Version::factory()->create();

        $this->assertTrue($version->trim->versions->first()->is($version));
        $this->assertTrue($version->engine->versions->first()->is($version));
        $this->assertTrue($version->transmission->versions->first()->is($version));
        $this->assertTrue($version->trim->carModel->trims->first()->is($version->trim));
        $this->assertTrue($version->trim->carModel->versions->first()->is($version));
    }

    public function test_the_car_model_of_a_version_is_derived_through_its_trim(): void
    {
        $version = Version::factory()->create();

        $this->assertTrue($version->carModel->is($version->trim->carModel));
        $this->assertTrue(Version::with('carModel')->find($version->id)->carModel->is($version->trim->carModel));
    }

    public function test_factories_build_a_consistent_chain(): void
    {
        $versions = Version::factory()->count(3)->create();

        $this->assertSame(3, CarModel::count());
        $this->assertSame(3, Trim::count());
        $this->assertSame(3, Engine::count());
        $this->assertSame(3, Transmission::count());
        foreach ($versions as $version) {
            $this->assertNotNull($version->trim->carModel);
            $this->assertTrue($version->is_active);
            $this->assertGreaterThan(0, $version->base_price_cents);
        }

        $model = CarModel::factory()->create();
        $trim = Trim::factory()->for($model)->create();
        $this->assertTrue($trim->carModel->is($model));
    }

    public function test_enums_are_cast_and_the_defaults_are_active(): void
    {
        $engine = Engine::create(['name' => '1.5 TSI', 'fuel_type' => 'petrol', 'power_kw' => 110])->fresh();
        $transmission = Transmission::create(['name' => 'DSG 7', 'type' => 'automatic', 'drive' => 'awd'])->fresh();

        $this->assertSame(FuelType::Petrol, $engine->fuel_type);
        $this->assertSame(110, $engine->power_kw);
        $this->assertSame(TransmissionType::Automatic, $transmission->type);
        $this->assertSame(DriveType::Awd, $transmission->drive);
        $this->assertTrue($engine->is_active);
        $this->assertTrue($transmission->is_active);
        $this->assertTrue(CarModel::create(['name' => 'Octavia', 'slug' => 'octavia'])->fresh()->is_active);
    }

    // --- unique combinations ---

    public function test_car_model_slug_is_unique(): void
    {
        CarModel::factory()->create(['slug' => 'octavia']);

        $this->expectException(QueryException::class);

        CarModel::factory()->create(['slug' => 'octavia']);
    }

    public function test_trim_name_is_unique_per_model_only(): void
    {
        $model = CarModel::factory()->create();
        Trim::factory()->for($model)->create(['name' => 'Style']);

        Trim::factory()->create(['name' => 'Style']);
        $this->assertSame(2, Trim::where('name', 'Style')->count());

        $this->expectException(QueryException::class);
        Trim::factory()->for($model)->create(['name' => 'Style']);
    }

    public function test_engine_and_transmission_combinations_are_unique(): void
    {
        $engine = ['name' => '2.0 TDI', 'fuel_type' => 'diesel', 'power_kw' => 110];
        Engine::factory()->create($engine);
        // Same name with another power is a different engine.
        Engine::factory()->create([...$engine, 'power_kw' => 147]);

        $transmission = ['name' => 'DSG 7', 'type' => 'automatic', 'drive' => 'fwd'];
        Transmission::factory()->create($transmission);
        Transmission::factory()->create([...$transmission, 'drive' => 'awd']);

        try {
            Engine::factory()->create($engine);
            $this->fail('Duplicate engine must be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        Transmission::factory()->create($transmission);
    }

    public function test_a_trim_engine_transmission_combination_is_unique_per_version(): void
    {
        $version = Version::factory()->create();

        // Another combination of the same parts is fine.
        Version::factory()->create(['trim_id' => $version->trim_id, 'engine_id' => $version->engine_id]);

        $this->expectException(QueryException::class);
        Version::factory()->create([
            'trim_id' => $version->trim_id,
            'engine_id' => $version->engine_id,
            'transmission_id' => $version->transmission_id,
        ]);
    }

    // --- restrict ---

    public function test_parents_with_children_cannot_be_deleted(): void
    {
        $version = Version::factory()->create();

        foreach ([$version->trim->carModel, $version->trim, $version->engine, $version->transmission] as $parent) {
            try {
                $parent->delete();
                $this->fail(class_basename($parent).' with children must not be deletable.');
            } catch (QueryException) {
                $this->assertNotNull($parent->fresh());
            }
        }

        $this->assertNotNull($version->fresh());
    }

    public function test_rows_without_children_can_be_deleted_bottom_up(): void
    {
        $version = Version::factory()->create();
        $trim = $version->trim;
        $model = $trim->carModel;
        $engine = $version->engine;
        $transmission = $version->transmission;

        $version->delete();
        $trim->delete();
        $model->delete();
        $engine->delete();
        $transmission->delete();

        $this->assertSame(0, CarModel::count() + Trim::count() + Engine::count() + Transmission::count() + Version::count());
    }

    // --- availability ---

    public function test_available_scope_requires_the_whole_chain_to_be_active(): void
    {
        $available = Version::factory()->create();

        $inactiveVersion = Version::factory()->inactive()->create();
        $inactiveTrim = Version::factory()->for(Trim::factory()->inactive())->create();
        $inactiveModel = Version::factory()->for(Trim::factory()->for(CarModel::factory()->inactive()))->create();
        $inactiveEngine = Version::factory()->for(Engine::factory()->inactive())->create();
        $inactiveTransmission = Version::factory()->for(Transmission::factory()->inactive())->create();

        $ids = Version::available()->pluck('id')->all();

        $this->assertSame([$available->id], $ids);
        foreach ([$inactiveVersion, $inactiveTrim, $inactiveModel, $inactiveEngine, $inactiveTransmission] as $excluded) {
            $this->assertNotContains($excluded->id, $ids);
            $this->assertNotNull(Version::find($excluded->id), 'Deactivated rows stay in the database.');
        }
    }

    public function test_deactivating_a_parent_removes_its_versions_from_the_available_scope(): void
    {
        $version = Version::factory()->create();
        $this->assertSame(1, Version::available()->count());

        $version->trim->carModel->update(['is_active' => false]);
        $this->assertSame(0, Version::available()->count());

        $version->trim->carModel->update(['is_active' => true]);
        $this->assertSame(1, Version::available()->count());
    }

    // --- prices ---

    public function test_prices_are_integer_cents_and_no_column_is_floating_point(): void
    {
        // Larger than 32 bits: the column is a bigint.
        $version = Version::factory()->create(['base_price_cents' => 9_000_000_000]);

        $this->assertIsInt($version->fresh()->base_price_cents);
        $this->assertSame(9_000_000_000, $version->fresh()->base_price_cents);
        $this->assertIsInt(\DB::table('versions')->where('id', $version->id)->value('base_price_cents'));

        foreach (self::TABLES as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $this->assertNotContains(
                    strtolower($column['type_name']),
                    ['float', 'double', 'decimal', 'numeric', 'real'],
                    "$table.{$column['name']} must not be floating point",
                );
            }
        }
    }

    public function test_a_price_change_is_logged_with_old_and_new_value(): void
    {
        $version = Version::factory()->create(['base_price_cents' => 2_500_000]);

        $version->update(['base_price_cents' => 2_600_000]);

        $log = ActivityLog::where('action', 'version.updated')->where('subject_id', $version->id)->sole();
        $this->assertEquals(['old' => 2_500_000, 'new' => 2_600_000], $log->changes['base_price_cents']);
        $this->assertArrayNotHasKey('redacted', $log->changes['base_price_cents']);
        $this->assertSame(['base_price_cents'], array_keys($log->changes));
    }

    public function test_catalog_changes_are_logged(): void
    {
        $model = CarModel::factory()->create();
        $model->update(['is_active' => false]);
        $model->delete();

        $this->assertSame(
            ['car_model.created', 'car_model.updated', 'car_model.deleted'],
            ActivityLog::where('subject_type', $model->getMorphClass())->orderBy('id')->pluck('action')->all(),
        );
    }

    // --- policies ---

    public function test_policy_matrix_for_every_catalog_entity(): void
    {
        $guestless = [
            CarModel::factory()->create(),
            Trim::factory()->create(),
            Engine::factory()->create(),
            Transmission::factory()->create(),
            Version::factory()->create(),
        ];
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();

        foreach ($guestless as $record) {
            $name = class_basename($record);

            $this->assertInstanceOf(AdminOnlyPolicy::class, Gate::getPolicyFor($record), $name);

            foreach (['viewAny' => $record::class, 'create' => $record::class, 'view' => $record, 'update' => $record, 'delete' => $record] as $ability => $argument) {
                $this->assertTrue(Gate::forUser($admin)->allows($ability, $argument), "admin $ability $name");
                $this->assertFalse(Gate::forUser($client)->allows($ability, $argument), "client $ability $name");
                $this->assertFalse(Gate::forUser(null)->allows($ability, $argument), "guest $ability $name");
            }
        }
    }
}
