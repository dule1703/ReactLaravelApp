<?php

namespace App\Console\Commands;

use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\ActivityLogger;
use App\Services\CarModelCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-off removal of the whole catalog (categories stay), e.g. to replace the demo catalog with
 * the real one. Rows go bottom-up through the models in one transaction. The safeguards run in
 * this order, after the summary is printed: offers, CATALOG_PURGE level, real-data marker,
 * --confirm. The default level is "off" and it is never set on production.
 */
class PurgeDemoCatalog extends Command
{
    protected $signature = 'catalog:purge-demo
        {--include-real : Also delete real data (needs CATALOG_PURGE=all)}
        {--confirm : Actually delete; without it the command only prints what it would delete}';

    protected $description = 'Delete the whole catalog (demo data) before the real catalog is loaded';

    private const MARKER = 'catalog_real_seeded_at';

    public function handle(ActivityLogger $logger, CarModelCategories $categories): int
    {
        $counts = [
            'trim_equipment' => TrimEquipment::count(),
            'version' => Version::count(),
            'equipment_item' => EquipmentItem::count(),
            'trim' => Trim::count(),
            'car_model' => CarModel::count(),
            'engine' => Engine::count(),
            'transmission' => Transmission::count(),
        ];
        $level = strtolower((string) config('catalog.purge'));
        $includeReal = (bool) $this->option('include-real');
        $marker = Setting::where('key', self::MARKER)->first();

        $this->summary($counts, $level);

        if ($table = $this->tableWithOffers()) {
            $this->error("Odbijeno: postoje ponude (tabela '$table' ima redove). Nijedan prekidač ovo ne zaobilazi.");

            return self::FAILURE;
        }

        if (! in_array($level, ['off', 'demo', 'all'], true)) {
            $this->error("Odbijeno: nepoznat nivo CATALOG_PURGE='$level' (dozvoljeno: off, demo, all).");

            return self::FAILURE;
        }

        if ($level === 'off') {
            $this->error('Odbijeno: brisanje kataloga je isključeno (CATALOG_PURGE=off). Podesite CATALOG_PURGE=demo (ili all) u .env samo na okruženju gde je to dozvoljeno.');

            return self::FAILURE;
        }

        if ($includeReal && $level !== 'all') {
            $this->error("Odbijeno: --include-real traži CATALOG_PURGE=all (trenutno: $level).");

            return self::FAILURE;
        }

        if ($marker !== null && ! $includeReal) {
            $this->error("Odbijeno: realni podaci su već učitani ({$marker->key}: {$marker->value}). Za njihovo brisanje treba CATALOG_PURGE=all i --include-real.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn('Ništa nije obrisano. Dodajte --confirm da potvrdite brisanje.');

            return self::FAILURE;
        }

        $images = [];

        DB::transaction(function () use ($logger, $categories, &$images) {
            // Per-row entries are suppressed (console only); one summary entry is written below.
            $logger->withoutLogging(function () use ($categories, &$images) {
                TrimEquipment::query()->get()->each->delete();
                Version::query()->get()->each->delete();
                EquipmentItem::query()->get()->each->delete();
                Trim::query()->get()->each->delete();

                CarModel::query()->get()->each(function (CarModel $model) use ($categories, &$images) {
                    $categories->sync($model, []);

                    if ($model->image_path !== null) {
                        $images[] = $model->image_path;
                    }

                    $model->delete();
                });

                Engine::query()->get()->each->delete();
                Transmission::query()->get()->each->delete();
            });
        });

        // Only after the commit: the files and the marker.
        foreach ($images as $path) {
            Storage::disk('public')->delete($path);
        }

        $logger->log(
            'catalog.purged',
            description: sprintf('level: %s; include_real: %s; environment: %s', $level, $includeReal ? 'yes' : 'no', app()->environment()),
            changes: array_map(fn (int $count) => ['old' => $count], array_filter($counts)),
        );

        if ($marker !== null) {
            $marker->delete();
            $this->line("Marker {$marker->key} je uklonjen.");
        }

        $this->info('Obrisano: '.collect($counts)->map(fn (int $count, string $entity) => "$entity: $count")->implode(', ').'. Slika obrisano: '.count($images).'. Kategorije su ostale.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summary(array $counts, string $level): void
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.$connection");

        $this->line('Okruženje (APP_ENV): '.app()->environment());
        $this->line("Konekcija: $connection (drajver: ".($config['driver'] ?? '-').')');
        $this->line('Baza: '.($config['database'] ?? '-'));
        $this->line('Host: '.($config['host'] ?? '-'));
        $this->line('Nivo CATALOG_PURGE: '.($level === '' ? '(prazno)' : $level));
        $this->line('Redova koji bi bili obrisani: '.collect($counts)->map(fn (int $count, string $entity) => "$entity: $count")->implode(', '));

        $names = CarModel::query()->orderBy('sort_order')->orderBy('id')->pluck('name');
        $this->line('Modeli: '.($names->isEmpty() ? '(nema)' : $names->implode(', ')));
    }

    private function tableWithOffers(): ?string
    {
        foreach ((array) config('catalog.offer_tables') as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return $table;
            }
        }

        return null;
    }
}
