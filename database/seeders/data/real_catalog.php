<?php

/*
|--------------------------------------------------------------------------
| Real catalog data (empty frame)
|--------------------------------------------------------------------------
| Filled in by hand by the owner from the official configurator and loaded with
|   php artisan catalog:validate-real          (checks the file, writes nothing)
|   php artisan db:seed --class=RealCatalogSeeder --force
|
| NOTHING is scraped or copied from the manufacturer's websites, and the repository is PUBLIC:
| only data entered by hand belongs here (no logos, pictures or protected marks). Model
| images are uploaded through the admin, never stored in git.
|
| An empty frame (all lists empty) is valid and the seeder does nothing. As soon as any list has
| content the whole file is validated first (all or nothing).
|
| PRICES are GROSS (with VAT) in integer cents, exactly as shown in the configurator (the source
| of truth). The seeder converts them to NET with Support\Vat::netFromGross using meta.vat_rate_bp
| FROM THIS FILE (not the current admin setting).
|
| Lists use explicit keys, never names as array keys: PHP silently drops a duplicated array key,
| a duplicated list entry is reported.
|
| Shape (all keys are required):
|
|   'meta' => [
|       'source' => 'where the data was read',      // non-empty text
|       'read_on' => '2026-01-31',                  // YYYY-MM-DD
|       'vat_rate_bp' => 2000,                      // 0..10000, 2000 = 20%
|   ],
|
|   'categories' => ['Poslovni', 'Gradski', ...],   // display order; the only names models may use
|
|   'models' => [
|       ['slug' => 'fabia', 'name' => 'Fabia', 'categories' => ['Gradski'], 'trims' => ['Essence', 'Style']],
|   ],
|
|   'engines' => [
|       ['key' => 'tsi10_70', 'name' => '1.0 TSI', 'fuel_type' => 'petrol', 'power_kw' => 70],
|   ],                                              // fuel_type: petrol|diesel|hybrid|phev|electric
|
|   'transmissions' => [
|       ['key' => 'm5', 'name' => 'Manuelni 5 brzina', 'type' => 'manual', 'drive' => 'fwd'],
|   ],                                              // type: manual|automatic, drive: fwd|awd|rwd
|
|   'versions' => [
|       ['model' => 'fabia', 'trim' => 'Essence', 'engine' => 'tsi10_70', 'transmission' => 'm5', 'gross_cents' => 1740000],
|   ],
|
|   'equipment' => [
|       [
|           'name' => 'Front Assist',
|           'category' => 'safety',                 // safety|comfort|exterior|interior|multimedia|driving
|           'models' => [
|               'fabia' => ['Essence' => 'S', 'Style' => ['O', 36000]],   // 'S' = standard (no price),
|           ],                                      // ['O', gross_cents] = optional, no entry = not available
|       ],
|   ],
*/

return [
    'meta' => [
        'source' => '',
        'read_on' => '',
        'vat_rate_bp' => 2000,
    ],
    'categories' => [],
    'models' => [],
    'engines' => [],
    'transmissions' => [],
    'versions' => [],
    'equipment' => [],
];
