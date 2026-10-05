<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\EquipmentItem;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use App\Support\InvalidRealCatalogException;
use App\Support\Vat;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesRealCatalogFiles;
use Tests\TestCase;

class RealCatalogSeederTest extends TestCase
{
    use RefreshDatabase, UsesRealCatalogFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCatalog($this->sample());
    }

    private function seedReal(): void
    {
        $this->seed(RealCatalogSeeder::class);
    }

    private function version(string $model, string $trim, string $engine): Version
    {
        return Version::whereHas('trim', fn ($q) => $q->where('name', $trim)->whereHas('carModel', fn ($m) => $m->where('slug', $model)))
            ->whereHas('engine', fn ($q) => $q->where('name', $engine))->sole();
    }

    private function equipmentRow(string $item, string $model, string $trim): ?TrimEquipment
    {
        return TrimEquipment::whereHas('equipmentItem', fn ($q) => $q->where('name', $item))
            ->whereHas('trim', fn ($q) => $q->where('name', $trim)->whereHas('carModel', fn ($m) => $m->where('slug', $model)))
            ->first();
    }

    // --- content ---

    public function test_the_sample_is_seeded_into_the_expected_rows(): void
    {
        $this->seedReal();

        $this->assertSame([
            'category' => 3, 'option_group' => 2, 'car_model' => 2, 'trim' => 5, 'engine' => 3, 'transmission' => 2,
            'version' => 6, 'equipment_item' => 9, 'trim_equipment' => 33,
        ], $this->catalogCounts());
        $this->assertSame(3, DB::table('car_model_category')->count());
        $this->assertSame(6, Version::available()->count());

        $this->assertSame(['Alfa', 'Beta'], CarModel::orderBy('sort_order')->pluck('name')->all());
        $this->assertSame([1, 2], CarModel::orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(['Basic', 'Plus', 'Top'], CarModel::where('slug', 'beta')->sole()->trims()->orderBy('sort_order')->pluck('name')->all());

        $engine = $this->version('beta', 'Top', 'Probni EV')->engine;
        $this->assertSame('electric', $engine->fuel_type->value);
        $this->assertSame(150, $engine->power_kw);
        $this->assertSame('rwd', $this->version('beta', 'Top', 'Probni EV')->transmission->drive->value);
    }

    public function test_gross_prices_become_net_prices_with_the_rate_of_the_file(): void
    {
        $this->seedReal();

        $this->assertSame(1_000_000, $this->version('alfa', 'Basic', '1.0 Probni')->base_price_cents);
        $this->assertSame(2_300_000, $this->version('beta', 'Plus', '2.0 Probni')->base_price_cents);

        $engineNames = array_column($this->sample()['engines'], 'name', 'key');

        foreach ($this->sample()['versions'] as $row) {
            $version = Version::whereHas('trim', fn ($q) => $q->where('name', $row['trim'])->whereHas('carModel', fn ($m) => $m->where('slug', $row['model'])))
                ->whereHas('engine', fn ($q) => $q->where('name', $engineNames[$row['engine']]))
                ->whereHas('transmission', fn ($q) => $q->where('name', array_column($this->sample()['transmissions'], 'name', 'key')[$row['transmission']]))
                ->sole();

            $this->assertSame(Vat::netFromGross($row['gross_cents'], 1000), $version->base_price_cents);
        }
    }

    public function test_the_current_vat_setting_does_not_change_the_result(): void
    {
        Setting::setVatRateBp(500);

        $this->seedReal();

        // Converted with the 10% of the file, not with the 5% of the setting (or the default 20%).
        $this->assertSame(Vat::netFromGross(1_100_000, 1000), $this->version('alfa', 'Basic', '1.0 Probni')->base_price_cents);
        $this->assertNotSame(Vat::netFromGross(1_100_000, 500), $this->version('alfa', 'Basic', '1.0 Probni')->base_price_cents);
        $this->assertSame(500, Setting::vatRateBp());
    }

    public function test_the_equipment_matrix_standard_optional_and_not_available(): void
    {
        $this->seedReal();

        // No entry = not available = no row.
        $this->assertNull($this->equipmentRow('Probni komfor', 'alfa', 'Basic'));

        $optional = $this->equipmentRow('Probni komfor', 'alfa', 'Plus');
        $this->assertSame('optional', $optional->availability->value);
        $this->assertSame(Vat::netFromGross(55_000, 1000), $optional->price_cents);

        $standard = $this->equipmentRow('Probni komfor', 'beta', 'Plus');
        $this->assertSame('standard', $standard->availability->value);
        $this->assertNull($standard->price_cents);

        $free = $this->equipmentRow('Besplatna opcija', 'alfa', 'Plus');
        $this->assertSame('optional', $free->availability->value);
        $this->assertSame(0, $free->price_cents);

        $this->assertSame('multimedia', EquipmentItem::where('name', 'Probni multimedijalni sistem')->sole()->category->value);
    }

    public function test_missing_categories_are_created_and_given_only_to_models_without_any(): void
    {
        // Alfa already exists with a category of its own: it keeps it.
        $own = Category::factory()->create(['name' => 'Moja', 'slug' => 'moja']);
        $alfa = CarModel::factory()->create(['name' => 'Alfa', 'slug' => 'alfa']);
        $alfa->categories()->attach($own);

        $this->seedReal();

        $this->assertEqualsCanonicalizing(['Probni gradski', 'Probni porodični', 'Probni sportski', 'Moja'], Category::pluck('name')->all());
        $this->assertSame(['Moja'], $alfa->categories()->pluck('categories.name')->all());
        $this->assertSame(
            ['Probni porodični', 'Probni sportski'],
            CarModel::where('slug', 'beta')->sole()->categories()->pluck('categories.name')->all(),
        );
        $this->assertSame([1, 2, 3], Category::whereIn('slug', ['probni-gradski', 'probni-porodicni', 'probni-sportski'])->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_the_order_of_the_seeders_does_not_matter(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seedReal();
        $this->seed(CategorySeeder::class);

        $this->assertSame(['Probni gradski'], CarModel::where('slug', 'alfa')->sole()->categories()->pluck('categories.name')->all());
        $this->assertSame(['Probni porodični', 'Probni sportski'], CarModel::where('slug', 'beta')->sole()->categories()->pluck('categories.name')->all());
        // The six demo categories and the three of the file.
        $this->assertSame(9, Category::count());
        $this->assertSame(3, DB::table('car_model_category')->count());
    }

    // --- idempotency ---

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->seedReal();
        $first = $this->catalogCounts();
        $timestamps = Version::pluck('updated_at', 'id')->all();

        $this->seedReal();

        $this->assertSame($first, $this->catalogCounts());
        $this->assertSame(3, DB::table('car_model_category')->count());
        $this->assertEquals($timestamps, Version::pluck('updated_at', 'id')->all());
    }

    public function test_running_again_never_overwrites_what_an_admin_changed(): void
    {
        $this->seedReal();

        $version = $this->version('alfa', 'Basic', '1.0 Probni');
        $version->update(['base_price_cents' => 1_234_500, 'is_active' => false]);
        $row = $this->equipmentRow('Probni komfor', 'alfa', 'Plus');
        $row->update(['price_cents' => 99_900]);
        $model = CarModel::where('slug', 'alfa')->sole();
        $model->update(['is_active' => false, 'sort_order' => 9]);
        $model->categories()->sync([]);

        $this->seedReal();

        $this->assertSame(1_234_500, $version->fresh()->base_price_cents);
        $this->assertFalse($version->fresh()->is_active);
        $this->assertSame(99_900, $row->fresh()->price_cents);
        $this->assertFalse($model->fresh()->is_active);
        $this->assertSame(9, $model->fresh()->sort_order);
        // A model that has no category at all gets the ones from the file again (documented).
        $this->assertSame(['Probni gradski'], $model->categories()->pluck('categories.name')->all());
    }

    public function test_a_deleted_row_comes_back_on_the_next_run(): void
    {
        $this->seedReal();
        $this->version('alfa', 'Basic', '1.0 Probni')->delete();

        $this->seedReal();

        $this->assertSame(6, Version::count());
    }

    public function test_names_are_matched_without_regard_to_case(): void
    {
        $model = CarModel::factory()->create(['name' => 'Alfa', 'slug' => 'alfa']);
        Trim::factory()->for($model)->create(['name' => 'BASIC']);
        EquipmentItem::factory()->create(['name' => 'PROBNA SIGURNOST', 'category' => 'safety']);

        $this->seedReal();

        $this->assertSame(1, Trim::where('car_model_id', $model->id)->whereRaw('lower(name) = ?', ['basic'])->count());
        $this->assertSame(1, EquipmentItem::whereRaw('lower(name) = ?', ['probna sigurnost'])->count());
        $this->assertSame(2, Trim::where('car_model_id', $model->id)->count());
    }

    // --- activity log and marker ---

    public function test_one_summary_entry_and_no_per_row_entries(): void
    {
        $this->seedReal();

        $this->assertSame(0, ActivityLog::whereIn('action', [
            'car_model.created', 'trim.created', 'engine.created', 'transmission.created', 'version.created',
            'equipment_item.created', 'trim_equipment.created', 'category.created', 'car_model.categories_changed',
        ])->count());

        $log = ActivityLog::where('action', 'catalog.real_seeded')->sole();
        $this->assertSame('system', $log->actor_type);
        $this->assertEquals([
            'category' => ['new' => 3], 'option_group' => ['new' => 2], 'car_model' => ['new' => 2], 'trim' => ['new' => 5],
            'engine' => ['new' => 3], 'transmission' => ['new' => 2], 'version' => ['new' => 6], 'equipment_item' => ['new' => 9],
            'trim_equipment' => ['new' => 33], 'car_model_category' => ['new' => 3],
        ], $log->changes);
        $this->assertStringContainsString('Izmišljeni probni podaci', $log->description);
        $this->assertStringContainsString('read_on: 2026-01-15', $log->description);
        $this->assertStringContainsString('vat_rate_bp: 1000', $log->description);
    }

    public function test_the_marker_is_set_once_and_logged(): void
    {
        $this->seedReal();

        $marker = Setting::where('key', 'catalog_real_seeded_at')->sole();
        $this->assertNotEmpty($marker->value);
        $this->assertSame(1, ActivityLog::where('action', 'setting.created')->where('subject_id', $marker->id)->count());

        $value = $marker->value;
        $this->version('alfa', 'Basic', '1.0 Probni')->delete();
        $this->seedReal();

        $this->assertSame($value, $marker->fresh()->value);
    }

    public function test_a_run_that_creates_nothing_writes_no_entry(): void
    {
        $this->seedReal();
        $entries = ActivityLog::count();

        $this->artisan('db:seed', ['--class' => RealCatalogSeeder::class])
            ->expectsOutputToContain('Sve iz fajla već postoji u bazi: ništa nije upisano.')
            ->assertExitCode(0);

        $this->assertSame($entries, ActivityLog::count());
        $this->assertSame(1, ActivityLog::where('action', 'catalog.real_seeded')->count());
    }

    public function test_a_difference_between_the_file_rate_and_the_setting_is_reported(): void
    {
        $this->artisan('db:seed', ['--class' => RealCatalogSeeder::class])
            ->expectsOutputToContain('stopa PDV-a u fajlu (10%) razlikuje se od trenutne u podešavanjima (20%)')
            ->assertExitCode(0);
    }

    public function test_a_failure_while_writing_rolls_everything_back(): void
    {
        $calls = 0;
        Version::creating(function () use (&$calls) {
            if (++$calls === 3) {
                throw new RuntimeException('write failed');
            }
        });

        try {
            $this->seedReal();
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('write failed', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    // --- file handling ---

    public function test_an_invalid_file_writes_nothing(): void
    {
        $catalog = $this->sample();
        $catalog['versions'][3]['engine'] = 'tsi15';
        $this->useCatalog($catalog);

        try {
            $this->seedReal();
            $this->fail('An invalid file must be rejected.');
        } catch (InvalidRealCatalogException $e) {
            $this->assertStringContainsString("versions[3]: nepoznat motor 'tsi15'", $e->getMessage());
            $this->assertStringContainsString('ništa nije upisano', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    public function test_a_missing_file_is_reported(): void
    {
        config(['catalog.real_catalog_path' => base_path('does/not/exist.php')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fajl sa realnim podacima ne postoji');

        $this->seedReal();
    }

    public function test_the_empty_frame_does_nothing_and_says_so(): void
    {
        config(['catalog.real_catalog_path' => database_path('seeders/data/real_catalog.php')]);

        $this->artisan('db:seed', ['--class' => RealCatalogSeeder::class])
            ->expectsOutputToContain('prazan okvir')
            ->assertExitCode(0);

        $this->assertNothingWritten();
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_the_database_seeder_runs_with_the_empty_frame(): void
    {
        config(['catalog.real_catalog_path' => database_path('seeders/data/real_catalog.php')]);

        $this->seed(DatabaseSeeder::class);

        $this->assertNothingWritten();
        $this->assertSame(2, User::where('role', 'client')->count());
    }

    public function test_the_database_seeder_loads_a_filled_file(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, Version::count());
        $this->assertSame(2, CarModel::count());
    }

    public function test_the_demo_seeders_still_work_independently(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(30, Version::count());
        $this->assertSame(6, Category::count());
    }
}
