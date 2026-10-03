<?php

namespace Database\Seeders;

use App\Models\CarModel;
use App\Models\Category;
use App\Services\ActivityLogger;
use App\Services\CarModelCategories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The six purpose-based categories (firstOrCreate by slug, in this order) and, for the demo
 * models only, their categories. The demo assignment touches a model ONLY when it has no
 * category at all, so categories an admin set by hand are never overwritten. The assignment is
 * demo data and is replaced by real data in 3.7.
 *
 * Idempotent; per-row log entries are suppressed (console only) and replaced by one
 * `category.seeded` summary entry, written only when something was created.
 *
 * On a server: php artisan db:seed --class=CategorySeeder --force
 */
class CategorySeeder extends Seeder
{
    private const CATEGORIES = ['Poslovni', 'Gradski', 'Porodični', 'SUV', 'Sportski', 'Električni'];

    /** model slug => category names (demo assignment) */
    private const DEMO = [
        'fabia' => ['Gradski'],
        'octavia' => ['Poslovni', 'Porodični'],
        'kodiaq' => ['SUV', 'Porodični'],
        'enyaq' => ['Električni', 'SUV'],
    ];

    public function run(): void
    {
        $logger = app(ActivityLogger::class);
        $created = ['category' => 0, 'car_model_category' => 0];

        $logger->withoutLogging(function () use (&$created) {
            foreach (self::CATEGORIES as $position => $name) {
                $category = Category::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'sort_order' => $position + 1, 'is_active' => true],
                );

                $created['category'] += $category->wasRecentlyCreated ? 1 : 0;
            }

            foreach (self::DEMO as $slug => $names) {
                $model = CarModel::where('slug', $slug)->first();

                if ($model === null || $model->categories()->exists()) {
                    continue;
                }

                $ids = Category::whereIn('slug', array_map(fn (string $name) => Str::slug($name), $names))->pluck('id')->all();
                app(CarModelCategories::class)->sync($model, $ids);
                $created['car_model_category'] += count($ids);
            }
        });

        $created = array_filter($created);

        if ($created !== []) {
            $logger->log('category.seeded', changes: array_map(fn (int $count) => ['new' => $count], $created));
        }
    }
}
