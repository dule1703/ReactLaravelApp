<?php

namespace Database\Seeders;

use App\Models\CarModel;
use App\Models\Category;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\ActivityLogger;
use App\Services\CarModelCategories;
use App\Support\InvalidRealCatalogException;
use App\Support\Money;
use App\Support\RealCatalog;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the hand-prepared real catalog (config catalog.real_catalog_path, format documented in
 * database/seeders/data/real_catalog.php). Prices in the file are GROSS; they are converted to
 * NET with Support\Vat::netFromGross and meta.vat_rate_bp FROM THE FILE (the admin setting may
 * change, the file is the source of truth).
 *
 * - The file is validated completely BEFORE anything is written; a bad file writes nothing.
 * - Everything is written in one transaction, only through the models (hooks apply).
 * - Idempotent and non-destructive: firstOrCreate by natural key, attributes (price, is_active,
 *   order) only when a row is created; never updateOrCreate. Names are matched without regard to
 *   case. Consequence: a row an admin deleted or renamed comes back on the next run; wrong data
 *   is fixed in the admin, not by re-seeding.
 * - Creates missing categories itself and gives them to models that have none, so the order in
 *   which seeders run does not matter.
 * - Per-row log entries are suppressed (console only); ONE `catalog.real_seeded` entry is
 *   written, only when something was created. On the first successful load it also stores the
 *   `catalog_real_seeded_at` marker that blocks catalog:purge-demo.
 *
 * On a server: php artisan db:seed --class=RealCatalogSeeder --force
 */
class RealCatalogSeeder extends Seeder
{
    /** @var array<string, int> rows created per entity in this run */
    private array $created = [];

    public function run(): void
    {
        $path = (string) config('catalog.real_catalog_path');
        $catalog = RealCatalog::load($path);

        if (RealCatalog::isEmpty($catalog)) {
            $this->command?->info('Fajl sa realnim podacima je prazan okvir: ništa nije upisano.');

            return;
        }

        $errors = RealCatalog::validate($catalog);

        if ($errors !== []) {
            throw new InvalidRealCatalogException($errors, $path);
        }

        $rate = $catalog['meta']['vat_rate_bp'];

        if ($rate !== Setting::vatRateBp()) {
            $this->command?->warn(sprintf(
                'Napomena: stopa PDV-a u fajlu (%s%%) razlikuje se od trenutne u podešavanjima (%s%%); cene su preračunate stopom iz fajla.',
                Money::formatPercentBp($rate),
                Money::formatPercentBp(Setting::vatRateBp()),
            ));
        }

        $this->created = [];
        $logger = app(ActivityLogger::class);

        DB::transaction(function () use ($catalog, $rate, $logger) {
            $logger->withoutLogging(fn () => $this->seed($catalog, $rate));

            if ($this->created === []) {
                return;
            }

            // Outside the suppression on purpose: both entries below are logged.
            Setting::firstOrCreate(
                ['key' => 'catalog_real_seeded_at'],
                ['value' => now()->toIso8601String()],
            );

            $logger->log(
                'catalog.real_seeded',
                description: sprintf('source: %s; read_on: %s; vat_rate_bp: %d', $catalog['meta']['source'], $catalog['meta']['read_on'], $rate),
                changes: array_map(fn (int $count) => ['new' => $count], $this->created),
            );
        });

        $this->command?->info($this->created === []
            ? 'Sve iz fajla već postoji u bazi: ništa nije upisano.'
            : 'Upisano: '.collect($this->created)->map(fn (int $count, string $entity) => "$entity: $count")->implode(', ').'.');
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function seed(array $catalog, int $rate): void
    {
        $categories = $this->categories($catalog['categories']);
        $groups = $this->groups($catalog['groups']);
        $trims = [];

        foreach ($catalog['models'] as $position => $model) {
            $row = $this->make(CarModel::class, ['slug' => $model['slug']], [
                'name' => $model['name'],
                'is_active' => true,
                'sort_order' => $position + 1,
            ], 'car_model');

            foreach ($model['trims'] as $trimPosition => $name) {
                $trims[$model['slug']][mb_strtolower($name)] = $this->make(Trim::class, ['car_model_id' => $row->id], [
                    'is_active' => true,
                    'sort_order' => $trimPosition + 1,
                ], 'trim', $name);
            }

            // Only a model without any category gets the ones from the file.
            if ($row->categories()->doesntExist() && $model['categories'] !== []) {
                $ids = collect($model['categories'])->map(fn (string $name) => $categories[mb_strtolower($name)]->id)->all();
                app(CarModelCategories::class)->sync($row, $ids);
                $this->created['car_model_category'] = ($this->created['car_model_category'] ?? 0) + count($ids);
            }
        }

        $engines = [];
        foreach ($catalog['engines'] as $engine) {
            $engines[$engine['key']] = $this->make(Engine::class, [
                'fuel_type' => $engine['fuel_type'],
                'power_kw' => $engine['power_kw'],
            ], ['is_active' => true], 'engine', $engine['name']);
        }

        $transmissions = [];
        foreach ($catalog['transmissions'] as $transmission) {
            $transmissions[$transmission['key']] = $this->make(Transmission::class, [
                'type' => $transmission['type'],
                'drive' => $transmission['drive'],
            ], ['is_active' => true], 'transmission', $transmission['name']);
        }

        foreach ($catalog['versions'] as $version) {
            $this->make(Version::class, [
                'trim_id' => $trims[$version['model']][mb_strtolower($version['trim'])]->id,
                'engine_id' => $engines[$version['engine']]->id,
                'transmission_id' => $transmissions[$version['transmission']]->id,
            ], [
                'base_price_cents' => Vat::netFromGross($version['gross_cents'], $rate),
                'is_active' => true,
            ], 'version');
        }

        $positions = [];
        foreach ($catalog['equipment'] as $item) {
            $positions[$item['category']] = ($positions[$item['category']] ?? 0) + 1;

            // Group and swatch are applied only when the item is created, like every other attribute.
            $equipment = $this->make(EquipmentItem::class, [], [
                'category' => $item['category'],
                'group_id' => isset($item['group']) ? $groups[mb_strtolower($item['group'])]->id : null,
                'swatch_hex' => isset($item['swatch_hex']) ? strtoupper($item['swatch_hex']) : null,
                'is_active' => true,
                'sort_order' => $positions[$item['category']],
            ], 'equipment_item', $item['name']);

            foreach ($item['models'] as $slug => $byTrim) {
                foreach ($byTrim as $trimName => $entry) {
                    $standard = $entry === 'S';

                    $this->make(TrimEquipment::class, [
                        'trim_id' => $trims[$slug][mb_strtolower((string) $trimName)]->id,
                        'equipment_item_id' => $equipment->id,
                    ], [
                        'availability' => $standard ? 'standard' : 'optional',
                        'price_cents' => $standard ? null : Vat::netFromGross($entry[1], $rate),
                    ], 'trim_equipment');
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $list
     * @return array<string, OptionGroup> lowercase name => group
     */
    private function groups(array $list): array
    {
        $groups = [];

        foreach ($list as $position => $group) {
            $groups[mb_strtolower($group['name'])] = $this->make(OptionGroup::class, ['slug' => Str::slug($group['name'])], [
                'name' => $group['name'],
                'category' => $group['category'],
                'selection' => $group['selection'],
                'uses_swatch' => ($group['swatch'] ?? false) === true,
                'sort_order' => $position + 1,
                'is_active' => true,
            ], 'option_group');
        }

        return $groups;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, Category> lowercase name => category
     */
    private function categories(array $names): array
    {
        $categories = [];

        foreach ($names as $position => $name) {
            $categories[mb_strtolower($name)] = $this->make(Category::class, ['slug' => Str::slug($name)], [
                'name' => $name,
                'sort_order' => $position + 1,
                'is_active' => true,
            ], 'category');
        }

        return $categories;
    }

    /**
     * firstOrCreate by natural key; the other attributes only apply on creation. With $name the
     * row is looked up by name WITHOUT regard to case (plus $key) and created with that name.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function make(string $class, array $key, array $attributes, string $entity, ?string $name = null): Model
    {
        if ($name === null) {
            $model = $class::firstOrCreate($key, $attributes);
        } else {
            $model = $class::query()->where($key)->whereRaw('lower(name) = lower(?)', [$name])->first()
                ?? $class::create($key + ['name' => $name] + $attributes);
        }

        if ($model->wasRecentlyCreated) {
            $this->created[$entity] = ($this->created[$entity] ?? 0) + 1;
        }

        return $model;
    }
}
