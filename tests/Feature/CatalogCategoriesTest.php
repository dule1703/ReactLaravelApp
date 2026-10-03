<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\Trim;
use App\Models\User;
use App\Models\Version;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogCategoriesTest extends TestCase
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
     * @return list<int>
     */
    private function categoryIds(CarModel $model): array
    {
        return $model->categories()->pluck('categories.id')->all();
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $category = Category::factory()->create();

        $requests = [
            ['get', '/admin/catalog/categories'], ['post', '/admin/catalog/categories'],
            ['patch', "/admin/catalog/categories/{$category->id}"],
            ['patch', "/admin/catalog/categories/{$category->id}/active"],
            ['delete', "/admin/catalog/categories/{$category->id}"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        $this->assertNotNull($category->fresh());
    }

    // --- categories CRUD ---

    public function test_the_list_shows_categories_with_the_number_of_models(): void
    {
        $category = Category::factory()->create(['name' => 'Gradski']);
        Category::factory()->create(['name' => 'SUV']);
        $model = CarModel::factory()->create();
        $model->categories()->attach($category);

        $this->as()->get('/admin/catalog/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Categories')
            ->has('items.data', 2)
            ->where('items.last_page', 1)
            ->where('items.data.0.name', 'Gradski')
            ->where('items.data.0.models_count', 1));

        $this->as()->get('/admin/catalog/categories?q=su')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)->where('items.data.0.name', 'SUV'));
    }

    public function test_a_category_is_created_with_a_generated_slug_and_the_next_order(): void
    {
        Category::factory()->create(['sort_order' => 4]);

        $this->as()->post('/admin/catalog/categories', ['name' => 'Terenski vozila'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Dodato.');

        $category = Category::where('name', 'Terenski vozila')->sole();
        $this->assertSame('terenski-vozila', $category->slug);
        $this->assertSame(5, $category->sort_order);
        $this->assertTrue($category->is_active);
    }

    public function test_category_names_are_unique_without_regard_to_case(): void
    {
        Category::factory()->create(['name' => 'Gradski', 'slug' => 'gradski']);

        foreach (['Gradski', 'gradski', 'GRADSKI', ' gradski '] as $name) {
            $this->as()->post('/admin/catalog/categories', ['name' => $name])
                ->assertSessionHasErrors(['name' => 'Kategorija sa tim nazivom već postoji.']);
        }
        $this->assertSame(1, Category::count());

        $category = Category::sole();
        $this->as()->patch("/admin/catalog/categories/{$category->id}", ['name' => 'GRADSKI'])->assertSessionHasNoErrors();
        $this->assertSame('GRADSKI', $category->fresh()->name);
        $this->assertSame('gradski', $category->fresh()->slug);
    }

    public function test_a_category_is_updated_and_logged_and_its_slug_stays(): void
    {
        $category = Category::factory()->create(['name' => 'Gradski', 'slug' => 'gradski', 'sort_order' => 2]);

        $this->as()->patch("/admin/catalog/categories/{$category->id}", ['name' => 'Mali gradski', 'sort_order' => '7', 'slug' => 'hacked'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Sačuvano.');

        $this->assertSame('gradski', $category->fresh()->slug);
        $log = ActivityLog::where('action', 'category.updated')->where('subject_id', $category->id)->sole();
        $this->assertEquals(['old' => 'Gradski', 'new' => 'Mali gradski'], $log->changes['name']);
        $this->assertEquals(['old' => 2, 'new' => 7], $log->changes['sort_order']);

        $this->as()->patch("/admin/catalog/categories/{$category->id}", ['name' => 'Mali gradski', 'sort_order' => '']);
        $this->assertSame(7, $category->fresh()->sort_order);
    }

    public function test_category_input_is_validated(): void
    {
        foreach ([['name' => ''], ['name' => str_repeat('a', 101)], ['name' => 'Ok', 'sort_order' => '65536'], ['name' => 'Ok', 'sort_order' => '-1']] as $payload) {
            $this->as()->post('/admin/catalog/categories', $payload)->assertSessionHasErrors();
        }
        $this->assertSame(0, Category::count());
    }

    public function test_a_category_can_be_deactivated_and_reactivated(): void
    {
        $category = Category::factory()->create();

        $this->as()->patch("/admin/catalog/categories/{$category->id}/active", ['is_active' => false])
            ->assertSessionHas('success', 'Status je promenjen.');
        $this->assertFalse($category->fresh()->is_active);

        $log = ActivityLog::where('action', 'category.updated')->where('subject_id', $category->id)->sole();
        $this->assertEquals(['old' => 1, 'new' => 0], $log->changes['is_active']);

        $this->as()->patch("/admin/catalog/categories/{$category->id}/active", ['is_active' => true]);
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_a_category_with_models_cannot_be_deleted_and_an_unused_one_can(): void
    {
        $used = Category::factory()->create();
        $unused = Category::factory()->create();
        CarModel::factory()->count(2)->create()->each(fn (CarModel $model) => $model->categories()->attach($used));

        $this->as()->delete("/admin/catalog/categories/{$used->id}")
            ->assertSessionHas('error', 'Kategorija ima zavisne redove (modela: 2). Deaktivirajte ga umesto brisanja.');
        $this->assertNotNull($used->fresh());

        $this->as()->delete("/admin/catalog/categories/{$unused->id}")->assertSessionHas('success', 'Obrisano.');
        $this->assertNull(Category::find($unused->id));
        $this->assertSame(1, ActivityLog::where('action', 'category.deleted')->where('subject_id', $unused->id)->count());
    }

    // --- categories of a model ---

    public function test_several_categories_can_be_assigned_changed_and_removed(): void
    {
        [$city, $suv, $family] = [
            Category::factory()->create(['name' => 'Gradski', 'sort_order' => 1]),
            Category::factory()->create(['name' => 'SUV', 'sort_order' => 2]),
            Category::factory()->create(['name' => 'Porodični', 'sort_order' => 3]),
        ];

        $this->as()->post('/admin/catalog/models', [
            'name' => 'Kodiaq', 'sync_categories' => 1, 'category_ids' => [$suv->id, $family->id],
        ])->assertSessionHasNoErrors();
        $model = CarModel::sole();
        $this->assertEqualsCanonicalizing([$suv->id, $family->id], $this->categoryIds($model));

        $this->as()->patch("/admin/catalog/models/{$model->id}", [
            'name' => 'Kodiaq', 'sync_categories' => 1, 'category_ids' => [$city->id, $suv->id],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$city->id, $suv->id], $this->categoryIds($model));

        // Nothing selected: the browser sends no category_ids at all.
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'Kodiaq', 'sync_categories' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->categoryIds($model));
    }

    public function test_without_the_sync_flag_the_categories_are_left_alone(): void
    {
        $category = Category::factory()->create();
        $model = CarModel::factory()->create();
        $model->categories()->attach($category);

        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'Renamed'])->assertSessionHasNoErrors();

        $this->assertSame([$category->id], $this->categoryIds($model));
    }

    public function test_a_change_of_categories_is_one_log_entry_with_old_and_new_names(): void
    {
        $city = Category::factory()->create(['name' => 'Gradski', 'sort_order' => 1]);
        $suv = Category::factory()->create(['name' => 'SUV', 'sort_order' => 2]);
        $family = Category::factory()->create(['name' => 'Porodični', 'sort_order' => 3]);
        $model = CarModel::factory()->create(['name' => 'Kodiaq']);

        $update = fn (array $ids) => $this->as()->patch("/admin/catalog/models/{$model->id}", [
            'name' => 'Kodiaq', 'sync_categories' => 1, 'category_ids' => $ids,
        ]);
        $logs = fn () => ActivityLog::where('action', 'car_model.categories_changed')->where('subject_id', $model->id)->orderBy('id')->get();

        $update([$suv->id, $family->id]);
        $this->assertCount(1, $logs());
        $this->assertEquals(['old' => '', 'new' => 'SUV, Porodični'], $logs()[0]->changes['categories']);

        $update([$city->id, $suv->id]);
        $this->assertCount(2, $logs());
        $this->assertEquals(['old' => 'SUV, Porodični', 'new' => 'Gradski, SUV'], $logs()[1]->changes['categories']);

        // Saving the same selection again writes nothing.
        $update([$suv->id, $city->id]);
        $this->assertCount(2, $logs());

        $update([]);
        $this->assertEquals(['old' => 'Gradski, SUV', 'new' => ''], $logs()[2]->changes['categories']);
    }

    public function test_renaming_and_changing_categories_in_one_save_gives_two_entries(): void
    {
        $category = Category::factory()->create(['name' => 'SUV']);
        $model = CarModel::factory()->create(['name' => 'Kodiaq']);

        $this->as()->patch("/admin/catalog/models/{$model->id}", [
            'name' => 'Kodiaq RS', 'sync_categories' => 1, 'category_ids' => [$category->id],
        ]);

        $this->assertSame(1, ActivityLog::where('action', 'car_model.updated')->where('subject_id', $model->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'car_model.categories_changed')->where('subject_id', $model->id)->count());
    }

    public function test_bad_category_ids_are_rejected(): void
    {
        $model = CarModel::factory()->create();
        $category = Category::factory()->create();

        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'X', 'sync_categories' => 1, 'category_ids' => [9999]])
            ->assertSessionHasErrors('category_ids.0');
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'X', 'sync_categories' => 1, 'category_ids' => [$category->id, $category->id]])
            ->assertSessionHasErrors('category_ids.0');
        $this->as()->patch("/admin/catalog/models/{$model->id}", ['name' => 'X', 'sync_categories' => 1, 'category_ids' => ['abc']])
            ->assertSessionHasErrors('category_ids.0');

        $this->assertSame([], $this->categoryIds($model));
    }

    public function test_the_model_list_shows_categories_and_filters_by_category(): void
    {
        $city = Category::factory()->create(['name' => 'Gradski', 'sort_order' => 1]);
        $suv = Category::factory()->create(['name' => 'SUV', 'sort_order' => 2]);
        $fabia = CarModel::factory()->create(['name' => 'Fabia', 'sort_order' => 1]);
        $kodiaq = CarModel::factory()->create(['name' => 'Kodiaq', 'sort_order' => 2]);
        CarModel::factory()->create(['name' => 'Bez kategorije', 'sort_order' => 3]);
        $fabia->categories()->attach($city);
        $kodiaq->categories()->attach([$city->id, $suv->id]);

        $this->as()->get('/admin/catalog/models')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 3)
            ->where('items.data.1.categories.0.name', 'Gradski')
            ->where('items.data.1.categories.1.name', 'SUV')
            ->where('items.data.2.categories', [])
            ->has('categories', 2));

        $names = fn (array $query) => array_column($this->as()->get('/admin/catalog/models?'.http_build_query($query))
            ->viewData('page')['props']['items']['data'], 'name');

        $this->assertSame(['Fabia', 'Kodiaq'], $names(['category' => $city->id]));
        $this->assertSame(['Kodiaq'], $names(['category' => $suv->id]));
        $this->assertSame(['Kodiaq'], $names(['category' => $city->id, 'q' => 'kod']));
        $this->assertSame([], $names(['category' => 9999]));
        $this->assertCount(3, $names([]));
    }

    public function test_an_inactive_category_does_not_break_the_model_or_availability(): void
    {
        $category = Category::factory()->create();
        $version = Version::factory()->create();
        $model = $version->trim->carModel;
        $model->categories()->attach($category);

        $this->as()->patch("/admin/catalog/categories/{$category->id}/active", ['is_active' => false]);

        $this->assertSame(1, Version::available()->count());
        $this->assertSame([$category->id], $this->categoryIds($model));

        $this->as()->get('/admin/catalog/models')->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.categories.0.is_active', false)
            ->where('items.data.0.available_versions', 1));

        // The model can still be edited and keeps the (inactive) category.
        $this->as()->patch("/admin/catalog/models/{$model->id}", [
            'name' => 'Renamed', 'sync_categories' => 1, 'category_ids' => [$category->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$category->id], $this->categoryIds($model));
    }

    public function test_deleting_a_model_detaches_its_categories_and_logs_it(): void
    {
        $category = Category::factory()->create(['name' => 'SUV']);
        $model = CarModel::factory()->create();
        $model->categories()->attach($category);

        $this->as()->delete("/admin/catalog/models/{$model->id}")->assertSessionHas('success', 'Obrisano.');

        $this->assertNull(CarModel::find($model->id));
        $this->assertSame(0, $category->carModels()->count());
        $log = ActivityLog::where('action', 'car_model.categories_changed')->where('subject_id', $model->id)->sole();
        $this->assertEquals(['old' => 'SUV', 'new' => ''], $log->changes['categories']);
    }

    public function test_a_model_with_trims_is_not_deleted_and_keeps_its_categories(): void
    {
        $category = Category::factory()->create();
        $trim = Trim::factory()->create();
        $model = $trim->carModel;
        $model->categories()->attach($category);

        $this->as()->delete("/admin/catalog/models/{$model->id}")
            ->assertSessionHas('error', 'Model ima zavisne redove (paketa: 1). Deaktivirajte ga umesto brisanja.');

        $this->assertNotNull($model->fresh());
        $this->assertSame([$category->id], $this->categoryIds($model));
        $this->assertSame(0, ActivityLog::where('action', 'car_model.categories_changed')->count());
    }

    public function test_the_same_model_and_category_cannot_be_linked_twice(): void
    {
        $category = Category::factory()->create();
        $model = CarModel::factory()->create();
        $model->categories()->attach($category);

        $this->expectException(QueryException::class);
        $model->categories()->attach($category);
    }

    public function test_a_linked_category_or_model_cannot_be_deleted_directly(): void
    {
        $category = Category::factory()->create();
        $model = CarModel::factory()->create();
        $model->categories()->attach($category);

        foreach ([$category, $model] as $row) {
            try {
                $row->delete();
                $this->fail(class_basename($row).' with links must not be deletable (foreign key RESTRICT).');
            } catch (QueryException) {
                $this->assertNotNull($row->fresh());
            }
        }
    }

    // --- seeder ---

    public function test_the_seeder_creates_the_six_categories_in_order(): void
    {
        $this->seed(CategorySeeder::class);

        $this->assertSame(
            ['Poslovni', 'Gradski', 'Porodični', 'SUV', 'Sportski', 'Električni'],
            Category::orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], Category::orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertTrue(Category::where('slug', 'elektricni')->exists());
    }

    public function test_the_seeder_assigns_demo_categories_to_demo_models_without_any(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CategorySeeder::class);

        $names = fn (string $slug) => CarModel::where('slug', $slug)->sole()->categories()->pluck('categories.name')->all();

        $this->assertSame(['Gradski'], $names('fabia'));
        $this->assertSame(['Poslovni', 'Porodični'], $names('octavia'));
        $this->assertSame(['Porodični', 'SUV'], $names('kodiaq'));
        $this->assertSame(['SUV', 'Električni'], $names('enyaq'));
    }

    public function test_the_seeder_is_idempotent_and_does_not_overwrite_manual_changes(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CategorySeeder::class);

        // An admin renames a category, changes Octavia's categories and categorizes a model.
        $city = Category::where('slug', 'gradski')->sole();
        $city->update(['name' => 'Mali gradski', 'is_active' => false, 'sort_order' => 40]);
        $octavia = CarModel::where('slug', 'octavia')->sole();
        $octavia->categories()->sync([Category::where('slug', 'sportski')->value('id')]);
        $counts = [Category::count(), DB::table('car_model_category')->count()];

        $this->seed(CategorySeeder::class);

        $this->assertSame($counts, [Category::count(), DB::table('car_model_category')->count()]);
        $this->assertSame('Mali gradski', $city->fresh()->name);
        $this->assertFalse($city->fresh()->is_active);
        $this->assertSame(40, $city->fresh()->sort_order);
        $this->assertSame(['Sportski'], $octavia->categories()->pluck('categories.name')->all());
    }

    public function test_the_seeder_does_not_touch_a_model_that_has_any_category_and_skips_missing_models(): void
    {
        $custom = Category::factory()->create(['name' => 'Moja', 'slug' => 'moja']);
        $fabia = CarModel::factory()->create(['name' => 'Fabia', 'slug' => 'fabia']);
        $fabia->categories()->attach($custom);

        $this->seed(CategorySeeder::class);

        $this->assertSame([$custom->id], $this->categoryIds($fabia));
        $this->assertSame(0, CarModel::where('slug', 'octavia')->count());
    }

    public function test_the_seeder_writes_one_summary_entry_and_no_per_row_entries(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(0, ActivityLog::whereIn('action', ['category.created', 'car_model.categories_changed'])->count());
        $log = ActivityLog::where('action', 'category.seeded')->sole();
        $this->assertEquals(['category' => ['new' => 6], 'car_model_category' => ['new' => 7]], $log->changes);

        $this->seed(CategorySeeder::class);
        $this->assertSame(1, ActivityLog::where('action', 'category.seeded')->count());
    }

    public function test_the_database_seeder_leaves_the_demo_categories_to_the_demo_seeders(): void
    {
        // The real catalog file creates its own categories; with the empty frame there are none.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Category::count());
        $this->assertSame(0, DB::table('car_model_category')->count());
    }

    // --- translations ---

    public function test_the_new_log_actions_and_fields_have_translations(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['created', 'updated', 'deleted', 'seeded'] as $event) {
            $this->assertNotEmpty($translations["activity.action.category.$event"] ?? null, "category.$event");
        }
        $this->assertNotEmpty($translations['activity.action.car_model.categories_changed'] ?? null);

        // Same lookup rule as resources/js/lib/activity.js: entity-specific key, then the generic one.
        $label = fn (string $entity, string $field) => $translations["activity.field.$entity.$field"] ?? $translations["activity.field.$field"] ?? null;
        $ignored = config('activity-log.ignored');

        foreach (['car_models' => 'car_model', 'categories' => 'category', 'car_model_category' => 'car_model_category'] as $table => $entity) {
            foreach (array_diff(Schema::getColumnListing($table), $ignored) as $column) {
                $this->assertNotEmpty($label($entity, $column), "no label for $entity.$column");
            }
        }

        foreach (['categories', 'image_path'] as $field) {
            $this->assertNotEmpty($label('car_model', $field), $field);
        }
        foreach (['category', 'car_model_category'] as $field) {
            $this->assertNotEmpty($label('category', $field), "category.seeded field $field");
        }
    }
}
