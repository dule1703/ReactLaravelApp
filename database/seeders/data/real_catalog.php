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
| only data entered by hand belongs here (no logos, pictures or protected marks). Model and
| equipment images are uploaded through the admin, never stored in git.
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
| Shape (all keys are required unless marked optional):
|
|   'meta' => [
|       'source' => 'where the data was read',      // non-empty text
|       'read_on' => '2026-01-31',                  // YYYY-MM-DD
|       'vat_rate_bp' => 2000,                      // 0..10000, 2000 = 20%
|   ],
|
|   'categories' => ['Poslovni', 'Gradski', ...],   // display order; the only names models may use
|
|   'groups' => [                                   // "one of several" choices: colors, wheels, upholstery
|       ['name' => 'Točkovi', 'selection' => 'single', 'category' => 'exterior'],
|       ['name' => 'Boje', 'selection' => 'single', 'category' => 'exterior', 'swatch' => true],  // swatch: optional
|   ],                                              // selection: single|multiple; category as for equipment
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
|       [
|           'name' => 'Alu felne 16"',
|           'category' => 'exterior',               // must equal the category of its group
|           'group' => 'Točkovi',                   // optional: the item is one choice of this group
|           'swatch_hex' => '#C62828',              // optional, only for items of a group with 'swatch' => true
|           'models' => ['fabia' => ['Essence' => 'S', 'Style' => ['O', 24000]]],
|       ],
|   ],
|
| ITEMS OF A GROUP ('group' set): the price in ['O', gross_cents] is the SURCHARGE over the group's
| standard item of that trim (the difference shown in the configurator), NOT a total price.
| Phase 4 (offers) must add it to the price of the trim, never replace the standard item's
| price with it. For a group with selection 'single', every trim that has at least one entry of
| the group needs EXACTLY ONE 'S' item (the standard one, no price); an item that is not
| available on a trim simply has no entry for it. A group with selection 'multiple' has no such
| rule. Items without a group are independent extras, as before.
*/

return [
    'meta' => [
        'source' => '',
        'read_on' => '',
        'vat_rate_bp' => 2000,
    ],
    'categories' => [],
    'groups' => [],
    'models' => [],
    'engines' => [],
    'transmissions' => [],
    'versions' => [],
    'equipment' => [],
];
