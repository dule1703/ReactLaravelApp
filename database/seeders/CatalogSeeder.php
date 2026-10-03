<?php

namespace Database\Seeders;

use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Demo catalog from database/seeders/data/catalog.php (approximate, made-up data).
 *
 * Idempotent and non-destructive: every row is firstOrCreate by its natural key and the other
 * attributes (price, is_active, ...) are applied only when the row is created, so running it
 * again never overwrites what an admin changed. Writes only through the models (rules and
 * hooks apply). Per-row log entries are suppressed (console only) and replaced by one
 * `catalog.seeded` summary entry, written only when something was created.
 *
 * On a server: php artisan db:seed --class=CatalogSeeder --force
 */
class CatalogSeeder extends Seeder
{
    /** @var array<string, int> rows created per entity in this run */
    private array $created = [];

    public function run(): void
    {
        $data = require database_path('seeders/data/catalog.php');
        $this->created = [];

        $logger = app(ActivityLogger::class);

        $logger->withoutLogging(fn () => $this->seed($data));

        if ($this->created !== []) {
            $logger->log(
                'catalog.seeded',
                changes: array_map(fn (int $count) => ['new' => $count], $this->created),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seed(array $data): void
    {
        $trims = [];
        $engines = [];
        $transmissions = [];

        foreach ($data['models'] as $position => $model) {
            $carModel = $this->make(CarModel::class, ['slug' => $model['slug']], [
                'name' => $model['name'],
                'is_active' => true,
                'sort_order' => $position + 1,
            ], 'car_model');

            foreach ($model['trims'] as $trimPosition => $name) {
                $trims[$model['slug']][$name] = $this->make(Trim::class, [
                    'car_model_id' => $carModel->id,
                    'name' => $name,
                ], ['is_active' => true, 'sort_order' => $trimPosition + 1], 'trim');
            }
        }

        foreach ($data['engines'] as $key => $engine) {
            $engines[$key] = $this->make(Engine::class, $engine, ['is_active' => true], 'engine');
        }

        foreach ($data['transmissions'] as $key => $transmission) {
            $transmissions[$key] = $this->make(Transmission::class, $transmission, ['is_active' => true], 'transmission');
        }

        foreach ($data['versions'] as $slug => $versions) {
            foreach ($versions as [$trimName, $engineKey, $transmissionKey, $priceCents]) {
                $this->make(Version::class, [
                    'trim_id' => $trims[$slug][$trimName]->id,
                    'engine_id' => $engines[$engineKey]->id,
                    'transmission_id' => $transmissions[$transmissionKey]->id,
                ], ['base_price_cents' => $priceCents, 'is_active' => true], 'version');
            }
        }

        foreach ($data['equipment'] as $category => $items) {
            foreach ($items as $position => $item) {
                $equipment = $this->make(EquipmentItem::class, ['name' => $item['name']], [
                    'category' => $category,
                    'is_active' => true,
                    'sort_order' => $position + 1,
                ], 'equipment_item');

                $this->seedMatrix($item, $equipment, $data['models'], $trims);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $models
     * @param  array<string, array<string, Trim>>  $trims
     */
    private function seedMatrix(array $item, EquipmentItem $equipment, array $models, array $trims): void
    {
        foreach ($models as $model) {
            $slug = $model['slug'];

            if (isset($item['only']) && ! in_array($slug, $item['only'], true)) {
                continue;
            }
            if (in_array($slug, $item['except'] ?? [], true)) {
                continue;
            }

            foreach ($model['trims'] as $level => $trimName) {
                $entry = $item['levels'][$level] ?? null;

                if ($entry === null) {
                    continue; // not available: no row
                }

                $standard = $entry === 'S';

                $this->make(TrimEquipment::class, [
                    'trim_id' => $trims[$slug][$trimName]->id,
                    'equipment_item_id' => $equipment->id,
                ], [
                    'availability' => $standard ? 'standard' : 'optional',
                    'price_cents' => $standard ? null : $entry[1],
                ], 'trim_equipment');
            }
        }
    }

    /**
     * firstOrCreate by natural key; the other attributes only apply on creation.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function make(string $class, array $key, array $attributes, string $entity): Model
    {
        $model = $class::firstOrCreate($key, $attributes);

        if ($model->wasRecentlyCreated) {
            $this->created[$entity] = ($this->created[$entity] ?? 0) + 1;
        }

        return $model;
    }
}
