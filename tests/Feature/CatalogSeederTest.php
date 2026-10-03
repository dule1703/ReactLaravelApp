<?php

namespace Tests\Feature;

use App\Enums\DriveType;
use App\Enums\EquipmentAvailability;
use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\ActivityLogger;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'car_model' => CarModel::count(),
            'trim' => Trim::count(),
            'engine' => Engine::count(),
            'transmission' => Transmission::count(),
            'version' => Version::count(),
            'equipment_item' => EquipmentItem::count(),
            'trim_equipment' => TrimEquipment::count(),
        ];
    }

    private function seedCatalog(): void
    {
        $this->seed(CatalogSeeder::class);
    }

    private function setRunningInConsole(bool $value): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, $value);
    }

    // --- content ---

    public function test_seeds_the_expected_catalog(): void
    {
        $this->seedCatalog();

        $counts = $this->counts();
        $this->assertSame(4, $counts['car_model']);
        $this->assertSame(12, $counts['trim']);
        $this->assertSame(9, $counts['engine']);
        $this->assertSame(6, $counts['transmission']);
        $this->assertSame(30, $counts['version']);
        $this->assertSame(30, $counts['equipment_item']);
        $this->assertGreaterThan(0, $counts['trim_equipment']);
        $this->assertSame(['Fabia', 'Octavia', 'Kodiaq', 'Enyaq'], CarModel::orderBy('sort_order')->pluck('name')->all());
    }

    public function test_every_model_has_a_trim_and_every_trim_an_available_version(): void
    {
        $this->seedCatalog();

        foreach (CarModel::all() as $model) {
            $this->assertGreaterThanOrEqual(1, $model->trims()->count(), $model->name);
        }
        foreach (Trim::with('carModel')->get() as $trim) {
            $this->assertGreaterThanOrEqual(
                1,
                Version::available()->where('trim_id', $trim->id)->count(),
                "{$trim->carModel->name} {$trim->name}",
            );
        }
        $this->assertSame(30, Version::available()->count());
    }

    public function test_fuel_types_and_drives_are_covered(): void
    {
        $this->seedCatalog();

        $this->assertEqualsCanonicalizing(
            ['petrol', 'hybrid', 'diesel', 'electric'],
            Engine::all()->pluck('fuel_type')->map->value->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['fwd', 'awd', 'rwd'],
            Transmission::all()->pluck('drive')->map->value->unique()->values()->all(),
        );

        // Enyaq 60 and 85 are rear-wheel drive, 85x is all-wheel drive.
        $drive = fn (string $engine) => Version::whereHas('engine', fn ($q) => $q->where('name', $engine))
            ->with('transmission')->get()->pluck('transmission.drive')->unique()->values()->all();
        $this->assertSame([DriveType::Rwd], $drive('Electric 60'));
        $this->assertSame([DriveType::Rwd], $drive('Electric 85'));
        $this->assertSame([DriveType::Awd], $drive('Electric 85x'));
    }

    public function test_all_prices_are_positive_whole_amounts_in_cents(): void
    {
        $this->seedCatalog();

        foreach (Version::all() as $version) {
            $this->assertIsInt($version->base_price_cents);
            $this->assertGreaterThan(0, $version->base_price_cents);
            $this->assertSame(0, $version->base_price_cents % 100, "version {$version->id}");
        }

        foreach (TrimEquipment::all() as $row) {
            if ($row->availability === EquipmentAvailability::Standard) {
                $this->assertNull($row->price_cents);
            } else {
                $this->assertIsInt($row->price_cents);
                $this->assertGreaterThanOrEqual(0, $row->price_cents);
                $this->assertSame(0, $row->price_cents % 100);
            }
        }
    }

    public function test_the_data_file_contains_no_floats(): void
    {
        $data = require database_path('seeders/data/catalog.php');

        array_walk_recursive($data, fn ($value) => $this->assertFalse(is_float($value), 'float in catalog data'));
    }

    public function test_a_higher_trim_has_a_superset_of_the_standard_equipment_of_a_lower_one(): void
    {
        $this->seedCatalog();

        foreach (CarModel::with('trims')->get() as $model) {
            $standard = $model->trims->sortBy('sort_order')->map(
                fn (Trim $trim) => $trim->trimEquipment()
                    ->where('availability', 'standard')->pluck('equipment_item_id')->all(),
            )->values();

            for ($i = 1; $i < $standard->count(); $i++) {
                $this->assertSame([], array_diff($standard[$i - 1], $standard[$i]), "{$model->name}: trim $i");
                $this->assertGreaterThan(count($standard[$i - 1]), count($standard[$i]), "{$model->name}: trim $i has more");
            }
        }
    }

    public function test_model_specific_equipment_exceptions(): void
    {
        $this->seedCatalog();

        $rows = fn (string $item, string $model) => TrimEquipment::whereHas('equipmentItem', fn ($q) => $q->where('name', $item))
            ->whereHas('trim.carModel', fn ($q) => $q->where('slug', $model))->count();

        $this->assertSame(0, $rows('Adaptivni tempomat (ACC)', 'fabia'));
        // ACC: not available on the lowest trim, optional on the middle one, standard on the top one.
        $this->assertSame(2, $rows('Adaptivni tempomat (ACC)', 'octavia'));
        $this->assertSame(3, $rows('Toplotna pumpa', 'enyaq'));
        $this->assertSame(0, $rows('Toplotna pumpa', 'kodiaq'));
        // "Not available" is a missing row, e.g. sport seats on the lower trims.
        $this->assertSame(1, $rows('Sportska sedišta', 'octavia'));
    }

    // --- idempotency ---

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->seedCatalog();
        $first = $this->counts();
        $timestamps = Version::pluck('updated_at', 'id')->all();

        $this->seedCatalog();

        $this->assertSame($first, $this->counts());
        $this->assertEquals($timestamps, Version::pluck('updated_at', 'id')->all());
    }

    public function test_running_again_never_overwrites_what_an_admin_changed(): void
    {
        $this->seedCatalog();

        $version = Version::first();
        $version->update(['base_price_cents' => 1_234_500, 'is_active' => false]);
        $engine = Engine::first();
        $engine->update(['is_active' => false]);
        $optional = TrimEquipment::where('availability', 'optional')->first();
        $optional->update(['price_cents' => 99_900]);
        $item = EquipmentItem::first();
        $item->update(['sort_order' => 77, 'is_active' => false]);

        $this->seedCatalog();

        $this->assertSame(1_234_500, $version->fresh()->base_price_cents);
        $this->assertFalse($version->fresh()->is_active);
        $this->assertFalse($engine->fresh()->is_active);
        $this->assertSame(99_900, $optional->fresh()->price_cents);
        $this->assertSame(77, $item->fresh()->sort_order);
        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_it_only_adds_what_is_missing(): void
    {
        $this->seedCatalog();
        $full = $this->counts();

        $removed = Version::first();
        $removed->delete();

        $this->seedCatalog();

        $this->assertSame($full, $this->counts());
        $this->assertNotNull(Version::where('trim_id', $removed->trim_id)->where('engine_id', $removed->engine_id)->first());
    }

    public function test_the_database_seeder_no_longer_seeds_the_demo_catalog(): void
    {
        // DatabaseSeeder loads the real catalog file (an empty frame until the data arrives);
        // the demo catalog is seeded explicitly with CatalogSeeder (development and tests).
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Version::count());
        $this->assertSame(0, CarModel::count());

        $this->seed(CatalogSeeder::class);
        $this->assertSame(30, Version::available()->count());
        $this->assertSame(4, CarModel::count());
    }

    // --- activity log ---

    public function test_seeding_writes_one_summary_entry_and_no_per_row_entries(): void
    {
        $this->seedCatalog();

        $this->assertSame(
            0,
            ActivityLog::whereIn('action', [
                'car_model.created', 'trim.created', 'engine.created', 'transmission.created',
                'version.created', 'equipment_item.created', 'trim_equipment.created',
            ])->count(),
        );

        $log = ActivityLog::where('action', 'catalog.seeded')->sole();
        $this->assertSame('system', $log->actor_type);
        $expected = array_map(fn (int $count) => ['new' => $count], $this->counts());
        $this->assertEquals($expected, $log->changes);
    }

    public function test_running_again_without_new_rows_writes_no_entry(): void
    {
        $this->seedCatalog();
        $this->seedCatalog();

        $this->assertSame(1, ActivityLog::where('action', 'catalog.seeded')->count());
    }

    public function test_a_rerun_after_a_deletion_logs_only_what_was_created(): void
    {
        $this->seedCatalog();
        Version::first()->delete();

        $this->seedCatalog();

        $latest = ActivityLog::where('action', 'catalog.seeded')->orderByDesc('id')->first();
        $this->assertSame(2, ActivityLog::where('action', 'catalog.seeded')->count());
        $this->assertEquals(['version' => ['new' => 1]], $latest->changes);
    }

    public function test_the_suppression_flag_is_reset_after_an_exception(): void
    {
        $logger = app(ActivityLogger::class);

        try {
            $logger->withoutLogging(function () {
                CarModel::factory()->create();
                throw new RuntimeException('seed failed half way');
            });
            $this->fail('The exception must propagate.');
        } catch (RuntimeException) {
            $this->assertSame(0, ActivityLog::where('action', 'car_model.created')->count());
        }

        CarModel::factory()->create();

        $this->assertSame(1, ActivityLog::where('action', 'car_model.created')->count());
    }

    public function test_the_flag_is_reset_after_a_normal_run_too(): void
    {
        $this->seedCatalog();

        CarModel::factory()->create();

        $this->assertSame(1, ActivityLog::where('action', 'car_model.created')->count());
    }

    public function test_logging_cannot_be_switched_off_outside_the_console(): void
    {
        $this->setRunningInConsole(false);

        try {
            $result = app(ActivityLogger::class)->withoutLogging(fn () => CarModel::factory()->create());
        } finally {
            $this->setRunningInConsole(true);
        }

        // The callback ran, and its change was logged.
        $this->assertInstanceOf(CarModel::class, $result);
        $this->assertSame(1, ActivityLog::where('action', 'car_model.created')->count());
    }

    public function test_the_summary_action_and_fields_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($translations['activity.action.catalog.seeded']);
        foreach (['car_model', 'trim', 'engine', 'transmission', 'version', 'equipment_item', 'trim_equipment'] as $entity) {
            $this->assertNotEmpty($translations["activity.field.catalog.$entity"], $entity);
        }
    }
}
