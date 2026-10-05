<?php

namespace App\Console\Commands;

use App\Support\Money;
use App\Support\RealCatalog;
use Illuminate\Console\Command;
use RuntimeException;

class ValidateRealCatalog extends Command
{
    protected $signature = 'catalog:validate-real {--path= : Alternative file (default: config catalog.real_catalog_path)}';

    protected $description = 'Validate the real catalog file without writing anything';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: config('catalog.real_catalog_path'));

        $this->line("Fajl: $path");

        try {
            $catalog = RealCatalog::load($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (RealCatalog::isEmpty($catalog)) {
            $this->info('Prazan okvir: nema podataka za učitavanje. Fajl je ispravan.');

            return self::SUCCESS;
        }

        if (RealCatalog::location($path) === 'repository') {
            $this->warn('Upozorenje: fajl sa podacima je unutar repozitorijuma, a nije u '.RealCatalog::PRIVATE_DIRECTORY.'/. '.RealCatalog::locationAdvice().' RealCatalogSeeder ga neće upisati.');
        }

        $errors = RealCatalog::validate($catalog);

        if ($errors !== []) {
            $this->error('Fajl nije ispravan ('.count($errors).' greške/a), ništa nije upisano:');
            foreach ($errors as $error) {
                $this->line("  - $error");
            }

            return self::FAILURE;
        }

        $counts = RealCatalog::counts($catalog);

        $this->line(sprintf('Izvor: %s (pročitano %s), PDV %s%%.', $catalog['meta']['source'], $catalog['meta']['read_on'], Money::formatPercentBp($catalog['meta']['vat_rate_bp'])));
        $this->line(sprintf(
            'Kategorije: %d, grupe opcija: %d, modeli: %d, motori: %d, menjači: %d, verzije: %d, stavke opreme: %d (unosa u matrici: %d).',
            $counts['categories'],
            $counts['groups'],
            $counts['models'],
            $counts['engines'],
            $counts['transmissions'],
            $counts['versions'],
            $counts['equipment'],
            $counts['equipment_entries'],
        ));
        $this->info('Fajl je ispravan.');

        return self::SUCCESS;
    }
}
