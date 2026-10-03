<?php

// Invented sample for the real-catalog tests: made-up names and prices, two models.
// Gross prices in cents; the VAT rate of the FILE is 10% (not the application default of 20%).

return [
    'meta' => [
        'source' => 'Izmišljeni probni podaci (nije stvaran cenovnik)',
        'read_on' => '2026-01-15',
        'vat_rate_bp' => 1000,
    ],

    'categories' => ['Probni gradski', 'Probni porodični', 'Probni sportski'],

    'groups' => [
        ['name' => 'Probni točkovi', 'selection' => 'single', 'category' => 'exterior'],
        ['name' => 'Probne boje', 'selection' => 'single', 'category' => 'exterior', 'swatch' => true],
    ],

    'models' => [
        ['slug' => 'alfa', 'name' => 'Alfa', 'categories' => ['Probni gradski'], 'trims' => ['Basic', 'Plus']],
        ['slug' => 'beta', 'name' => 'Beta', 'categories' => ['Probni porodični', 'Probni sportski'], 'trims' => ['Basic', 'Plus', 'Top']],
    ],

    'engines' => [
        ['key' => 'e_petrol', 'name' => '1.0 Probni', 'fuel_type' => 'petrol', 'power_kw' => 70],
        ['key' => 'e_diesel', 'name' => '2.0 Probni', 'fuel_type' => 'diesel', 'power_kw' => 110],
        ['key' => 'e_ev', 'name' => 'Probni EV', 'fuel_type' => 'electric', 'power_kw' => 150],
    ],

    'transmissions' => [
        ['key' => 't_man', 'name' => 'Probni ručni', 'type' => 'manual', 'drive' => 'fwd'],
        ['key' => 't_auto', 'name' => 'Probni automatski', 'type' => 'automatic', 'drive' => 'rwd'],
    ],

    'versions' => [
        ['model' => 'alfa', 'trim' => 'Basic', 'engine' => 'e_petrol', 'transmission' => 't_man', 'gross_cents' => 1_100_000],
        ['model' => 'alfa', 'trim' => 'Plus', 'engine' => 'e_petrol', 'transmission' => 't_man', 'gross_cents' => 1_320_000],
        ['model' => 'beta', 'trim' => 'Basic', 'engine' => 'e_diesel', 'transmission' => 't_man', 'gross_cents' => 2_200_000],
        ['model' => 'beta', 'trim' => 'Plus', 'engine' => 'e_diesel', 'transmission' => 't_auto', 'gross_cents' => 2_530_000],
        ['model' => 'beta', 'trim' => 'Top', 'engine' => 'e_ev', 'transmission' => 't_auto', 'gross_cents' => 3_300_000],
        ['model' => 'beta', 'trim' => 'Top', 'engine' => 'e_diesel', 'transmission' => 't_auto', 'gross_cents' => 2_970_000],
    ],

    'equipment' => [
        [
            'name' => 'Probna sigurnost',
            'category' => 'safety',
            'models' => [
                'alfa' => ['Basic' => 'S', 'Plus' => 'S'],
                'beta' => ['Basic' => 'S', 'Plus' => 'S', 'Top' => 'S'],
            ],
        ],
        [
            'name' => 'Probni komfor',
            'category' => 'comfort',
            'models' => [
                // Alfa Basic has no entry: not available.
                'alfa' => ['Plus' => ['O', 55_000]],
                'beta' => ['Basic' => ['O', 66_000], 'Plus' => 'S', 'Top' => 'S'],
            ],
        ],
        [
            'name' => 'Probni multimedijalni sistem',
            'category' => 'multimedia',
            'models' => [
                'beta' => ['Plus' => ['O', 110_000], 'Top' => 'S'],
            ],
        ],
        // Option group "wheels": per trim exactly one standard item; the other prices are
        // SURCHARGES over it. An item that is not available on a trim has no entry.
        [
            'name' => 'Probni točkovi 16"',
            'category' => 'exterior',
            'group' => 'Probni točkovi',
            'models' => [
                'alfa' => ['Basic' => 'S'],
                'beta' => ['Basic' => 'S'],
            ],
        ],
        [
            'name' => 'Probni točkovi 17"',
            'category' => 'exterior',
            'group' => 'Probni točkovi',
            'models' => [
                'alfa' => ['Basic' => ['O', 44_000], 'Plus' => 'S'],
                'beta' => ['Basic' => ['O', 44_000], 'Plus' => 'S'],
            ],
        ],
        [
            'name' => 'Probni točkovi 18"',
            'category' => 'exterior',
            'group' => 'Probni točkovi',
            'models' => [
                'alfa' => ['Basic' => ['O', 99_000], 'Plus' => ['O', 55_000]],
                'beta' => ['Basic' => ['O', 99_000], 'Plus' => ['O', 55_000], 'Top' => 'S'],
            ],
        ],
        // Option group "colors" with swatches: the first color is standard everywhere.
        [
            'name' => 'Probna bela',
            'category' => 'exterior',
            'group' => 'Probne boje',
            'swatch_hex' => '#FFFFFF',
            'models' => [
                'alfa' => ['Basic' => 'S', 'Plus' => 'S'],
                'beta' => ['Basic' => 'S', 'Plus' => 'S', 'Top' => 'S'],
            ],
        ],
        [
            'name' => 'Probna crvena',
            'category' => 'exterior',
            'group' => 'Probne boje',
            'swatch_hex' => '#C62828',
            'models' => [
                'alfa' => ['Basic' => ['O', 33_000], 'Plus' => ['O', 33_000]],
                'beta' => ['Basic' => ['O', 33_000], 'Plus' => ['O', 33_000], 'Top' => ['O', 33_000]],
            ],
        ],
        [
            'name' => 'Besplatna opcija',
            'category' => 'interior',
            'models' => [
                'alfa' => ['Plus' => ['O', 0]],
            ],
        ],
    ],
];
