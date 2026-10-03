<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\Engine;
use App\Models\Setting;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\UsesRealCatalogFiles;
use Tests\TestCase;

class CatalogPurgeCommandTest extends TestCase
{
    use RefreshDatabase, UsesRealCatalogFiles;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CatalogSeeder::class);
        $this->seed(CategorySeeder::class);
    }

    private function level(string $level): void
    {
        config(['catalog.purge' => $level]);
    }

    private function marker(): Setting
    {
        return Setting::create(['key' => 'catalog_real_seeded_at', 'value' => '2026-02-01T10:00:00+00:00']);
    }

    private function assertCatalogIntact(): void
    {
        $this->assertSame(4, CarModel::count());
        $this->assertGreaterThan(0, DB::table('versions')->count());
        $this->assertGreaterThan(0, DB::table('trim_equipment')->count());
        $this->assertSame(7, DB::table('car_model_category')->count());
        $this->assertSame(0, ActivityLog::where('action', 'catalog.purged')->count());
    }

    // --- what is printed first ---

    public function test_the_summary_is_printed_before_any_safeguard(): void
    {
        $this->artisan('catalog:purge-demo')
            ->expectsOutputToContain('Okruženje (APP_ENV): testing')
            ->expectsOutputToContain('Konekcija: sqlite (drajver: sqlite)')
            ->expectsOutputToContain('Baza: :memory:')
            ->expectsOutputToContain('Host: -')
            ->expectsOutputToContain('Nivo CATALOG_PURGE: off')
            ->expectsOutputToContain('Redova koji bi bili obrisani: trim_equipment: 267, version: 30, equipment_item: 30, trim: 12, car_model: 4, engine: 9, transmission: 6')
            ->expectsOutputToContain('Modeli: Fabia, Octavia, Kodiaq, Enyaq')
            ->assertExitCode(1);

        $this->assertCatalogIntact();
    }

    // --- safeguards, in order ---

    public function test_the_default_level_is_off_and_refuses(): void
    {
        $this->assertSame('off', config('catalog.purge'));

        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain('Odbijeno: brisanje kataloga je isključeno (CATALOG_PURGE=off)')
            ->assertExitCode(1);

        $this->assertCatalogIntact();
    }

    public function test_offers_block_everything_and_come_first(): void
    {
        Schema::create('offers', function ($table) {
            $table->id();
        });
        DB::table('offers')->insert(['id' => 1]);

        // Even the most permissive combination cannot get past an offer.
        $this->level('all');
        $this->marker();

        $this->artisan('catalog:purge-demo', ['--include-real' => true, '--confirm' => true])
            ->expectsOutputToContain("Odbijeno: postoje ponude (tabela 'offers' ima redove). Nijedan prekidač ovo ne zaobilazi.")
            ->assertExitCode(1);

        $this->assertSame(4, CarModel::count());
        $this->assertSame(0, ActivityLog::where('action', 'catalog.purged')->count());
    }

    public function test_the_offer_check_comes_before_the_level_check(): void
    {
        Schema::create('offers', function ($table) {
            $table->id();
        });
        DB::table('offers')->insert(['id' => 1]);

        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain("Odbijeno: postoje ponude (tabela 'offers'")
            ->doesntExpectOutputToContain('isključeno')
            ->assertExitCode(1);
    }

    public function test_an_empty_offers_table_does_not_block_and_the_tables_come_from_the_configuration(): void
    {
        Schema::create('offers', function ($table) {
            $table->id();
        });

        $this->level('demo');
        $this->artisan('catalog:purge-demo', ['--confirm' => true])->assertExitCode(0);
        $this->assertSame(0, CarModel::count());
    }

    public function test_other_offer_tables_from_the_configuration_are_checked_too(): void
    {
        Schema::create('quotes', function ($table) {
            $table->id();
        });
        DB::table('quotes')->insert(['id' => 1]);
        config(['catalog.offer_tables' => ['offers', 'offer_items', 'quotes']]);
        $this->level('demo');

        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain("tabela 'quotes'")
            ->assertExitCode(1);

        $this->assertSame(4, CarModel::count());
    }

    public function test_an_unknown_level_refuses(): void
    {
        $this->level('everything');

        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain("nepoznat nivo CATALOG_PURGE='everything'")
            ->assertExitCode(1);

        $this->assertCatalogIntact();
    }

    public function test_include_real_needs_the_all_level(): void
    {
        // At the off level the "off" refusal comes first; at demo the message names the missing level.
        $this->artisan('catalog:purge-demo', ['--include-real' => true, '--confirm' => true])
            ->expectsOutputToContain('Odbijeno: brisanje kataloga je isključeno (CATALOG_PURGE=off)')
            ->assertExitCode(1);

        $this->level('demo');
        $this->artisan('catalog:purge-demo', ['--include-real' => true, '--confirm' => true])
            ->expectsOutputToContain('Odbijeno: --include-real traži CATALOG_PURGE=all (trenutno: demo)')
            ->assertExitCode(1);

        $this->assertCatalogIntact();
    }

    public function test_the_real_data_marker_blocks_the_demo_level_and_all_without_include_real(): void
    {
        $this->marker();

        foreach (['demo', 'all'] as $level) {
            $this->level($level);

            $this->artisan('catalog:purge-demo', ['--confirm' => true])
                ->expectsOutputToContain('Odbijeno: realni podaci su već učitani (catalog_real_seeded_at: 2026-02-01T10:00:00+00:00)')
                ->assertExitCode(1);
        }

        $this->assertCatalogIntact();
        $this->assertNotNull(Setting::where('key', 'catalog_real_seeded_at')->first());
    }

    public function test_without_confirm_nothing_is_deleted(): void
    {
        $this->level('demo');

        $this->artisan('catalog:purge-demo')
            ->expectsOutputToContain('Ništa nije obrisano. Dodajte --confirm da potvrdite brisanje.')
            ->assertExitCode(1);

        $this->assertCatalogIntact();
    }

    // --- the purge ---

    public function test_demo_level_deletes_the_whole_catalog_but_keeps_the_categories_and_logs_once(): void
    {
        $model = CarModel::where('slug', 'fabia')->sole();
        Storage::disk('public')->put('catalog/models/fabia.png', 'x');
        $model->update(['image_path' => 'catalog/models/fabia.png']);
        $logsBefore = ActivityLog::count();
        $this->level('demo');

        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain('transmission: 6. Slika obrisano: 1. Kategorije su ostale.')
            ->assertExitCode(0);

        foreach (['trim_equipment', 'versions', 'equipment_items', 'trims', 'car_models', 'engines', 'transmissions', 'car_model_category'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(6, Category::count());
        Storage::disk('public')->assertMissing('catalog/models/fabia.png');

        // One summary entry; no per-row entries.
        $this->assertSame($logsBefore + 1, ActivityLog::count());
        $log = ActivityLog::where('action', 'catalog.purged')->sole();
        $this->assertSame('system', $log->actor_type);
        $this->assertEquals(30, $log->changes['version']['old']);
        $this->assertEquals(4, $log->changes['car_model']['old']);
        $this->assertStringContainsString('level: demo', $log->description);
        $this->assertStringContainsString('include_real: no', $log->description);
        $this->assertStringContainsString('environment: testing', $log->description);
        $this->assertSame(0, ActivityLog::whereIn('action', ['car_model.deleted', 'version.deleted', 'trim_equipment.deleted', 'car_model.categories_changed'])->count());
    }

    public function test_the_all_level_without_a_marker_does_not_need_include_real(): void
    {
        $this->level('all');

        $this->artisan('catalog:purge-demo', ['--confirm' => true])->assertExitCode(0);

        $this->assertSame(0, CarModel::count());
    }

    public function test_include_real_removes_the_marker_after_the_commit_and_logs_it(): void
    {
        $marker = $this->marker();
        $this->level('all');

        $this->artisan('catalog:purge-demo', ['--include-real' => true, '--confirm' => true])
            ->expectsOutputToContain('Marker catalog_real_seeded_at je uklonjen.')
            ->assertExitCode(0);

        $this->assertSame(0, CarModel::count());
        $this->assertNull(Setting::where('key', 'catalog_real_seeded_at')->first());
        $this->assertSame(1, ActivityLog::where('action', 'setting.deleted')->where('subject_id', $marker->id)->count());
        $log = ActivityLog::where('action', 'catalog.purged')->sole();
        $this->assertStringContainsString('include_real: yes', $log->description);
    }

    public function test_a_failure_in_the_middle_deletes_nothing_and_keeps_the_files(): void
    {
        $model = CarModel::where('slug', 'fabia')->sole();
        Storage::disk('public')->put('catalog/models/fabia.png', 'x');
        $model->update(['image_path' => 'catalog/models/fabia.png']);
        $this->level('demo');

        Engine::deleting(function () {
            throw new RuntimeException('delete failed');
        });

        try {
            $this->artisan('catalog:purge-demo', ['--confirm' => true])->run();
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('delete failed', $e->getMessage());
        }

        $this->assertSame(4, CarModel::count());
        $this->assertSame(30, DB::table('versions')->count());
        $this->assertSame(7, DB::table('car_model_category')->count());
        Storage::disk('public')->assertExists('catalog/models/fabia.png');
        $this->assertSame(0, ActivityLog::where('action', 'catalog.purged')->count());
    }

    public function test_after_a_purge_the_real_catalog_can_be_loaded_with_the_same_slugs(): void
    {
        // The reason for the purge: real models reuse the demo slugs.
        // The sample's first model reuses the demo slug "fabia" everywhere it is referenced.
        $this->useCatalog(eval('return '.str_replace("'alfa'", "'fabia'", var_export($this->sample(), true)).';'));
        $this->level('demo');

        $this->artisan('catalog:purge-demo', ['--confirm' => true])->assertExitCode(0);
        $this->seed(RealCatalogSeeder::class);

        $fabia = CarModel::where('slug', 'fabia')->sole();
        $this->assertSame('Alfa', $fabia->name);
        $this->assertSame(2, $fabia->trims()->count());
        // ... and from now on the purge refuses at the demo level.
        $this->artisan('catalog:purge-demo', ['--confirm' => true])
            ->expectsOutputToContain('realni podaci su već učitani')
            ->assertExitCode(1);
    }
}
