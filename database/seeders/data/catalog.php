<?php

/*
|--------------------------------------------------------------------------
| Demo catalog data
|--------------------------------------------------------------------------
| APPROXIMATE, hand-entered demo data. It is NOT the official Škoda price list and was not
| copied from any website. Names and prices are made up to look plausible.
|
| Prices are NET (without VAT) in integer cents, in round amounts: 2_150_000 = 21,500.00 EUR.
|
| Matrix levels: [lower trim, middle trim, top trim] of each model (position in the model's
| `trims` list). 'S' = standard (no price), ['O', cents] = optional with a net price,
| null = not available (no row). Higher trims always have a superset of the standard
| equipment of lower ones. `except` / `only` limit an item to some models (by slug).
*/

return [
    'models' => [
        ['slug' => 'fabia', 'name' => 'Fabia', 'trims' => ['Essence', 'Ambition', 'Style']],
        ['slug' => 'octavia', 'name' => 'Octavia', 'trims' => ['Ambition', 'Style', 'Sportline']],
        ['slug' => 'kodiaq', 'name' => 'Kodiaq', 'trims' => ['Ambition', 'Style', 'Sportline']],
        ['slug' => 'enyaq', 'name' => 'Enyaq', 'trims' => ['Ambition', 'Style', 'Sportline']],
    ],

    'engines' => [
        'tsi10_70' => ['name' => '1.0 TSI', 'fuel_type' => 'petrol', 'power_kw' => 70],
        'tsi10_85' => ['name' => '1.0 TSI', 'fuel_type' => 'petrol', 'power_kw' => 85],
        'tsi15_110' => ['name' => '1.5 TSI', 'fuel_type' => 'petrol', 'power_kw' => 110],
        'mhev15_110' => ['name' => '1.5 TSI mHEV', 'fuel_type' => 'hybrid', 'power_kw' => 110],
        'tdi20_110' => ['name' => '2.0 TDI', 'fuel_type' => 'diesel', 'power_kw' => 110],
        'tdi20_147' => ['name' => '2.0 TDI', 'fuel_type' => 'diesel', 'power_kw' => 147],
        'ev60' => ['name' => 'Electric 60', 'fuel_type' => 'electric', 'power_kw' => 132],
        'ev85' => ['name' => 'Electric 85', 'fuel_type' => 'electric', 'power_kw' => 210],
        'ev85x' => ['name' => 'Electric 85x', 'fuel_type' => 'electric', 'power_kw' => 220],
    ],

    'transmissions' => [
        'm5' => ['name' => 'Manuelni 5 brzina', 'type' => 'manual', 'drive' => 'fwd'],
        'm6' => ['name' => 'Manuelni 6 brzina', 'type' => 'manual', 'drive' => 'fwd'],
        'dsg7' => ['name' => 'DSG 7 brzina', 'type' => 'automatic', 'drive' => 'fwd'],
        'dsg7_4x4' => ['name' => 'DSG 7 brzina 4x4', 'type' => 'automatic', 'drive' => 'awd'],
        'auto1' => ['name' => 'Automatski 1 stepen', 'type' => 'automatic', 'drive' => 'rwd'],
        'auto1_4x4' => ['name' => 'Automatski 1 stepen 4x4', 'type' => 'automatic', 'drive' => 'awd'],
    ],

    // model slug => [trim name, engine key, transmission key, net price in cents]
    'versions' => [
        'fabia' => [
            ['Essence', 'tsi10_70', 'm5', 1_450_000],
            ['Essence', 'tsi10_85', 'm6', 1_540_000],
            ['Ambition', 'tsi10_70', 'm5', 1_620_000],
            ['Ambition', 'tsi10_85', 'm6', 1_710_000],
            ['Ambition', 'tsi10_85', 'dsg7', 1_880_000],
            ['Style', 'tsi10_85', 'm6', 1_900_000],
            ['Style', 'tsi10_85', 'dsg7', 2_070_000],
        ],
        'octavia' => [
            ['Ambition', 'tsi15_110', 'm6', 2_150_000],
            ['Ambition', 'tsi15_110', 'dsg7', 2_320_000],
            ['Ambition', 'tdi20_110', 'm6', 2_400_000],
            ['Style', 'tsi15_110', 'dsg7', 2_560_000],
            ['Style', 'mhev15_110', 'dsg7', 2_690_000],
            ['Style', 'tdi20_110', 'm6', 2_620_000],
            ['Style', 'tdi20_110', 'dsg7', 2_780_000],
            ['Sportline', 'tsi15_110', 'dsg7', 2_790_000],
            ['Sportline', 'mhev15_110', 'dsg7', 2_920_000],
            ['Sportline', 'tdi20_110', 'dsg7', 3_010_000],
        ],
        'kodiaq' => [
            ['Ambition', 'tsi15_110', 'dsg7', 3_000_000],
            ['Ambition', 'tdi20_110', 'dsg7', 3_280_000],
            ['Style', 'tsi15_110', 'dsg7', 3_350_000],
            ['Style', 'tdi20_110', 'dsg7', 3_620_000],
            ['Style', 'tdi20_147', 'dsg7_4x4', 3_950_000],
            ['Sportline', 'tsi15_110', 'dsg7', 3_650_000],
            ['Sportline', 'tdi20_147', 'dsg7_4x4', 4_250_000],
        ],
        'enyaq' => [
            ['Ambition', 'ev60', 'auto1', 3_050_000],
            ['Ambition', 'ev85', 'auto1', 3_450_000],
            ['Style', 'ev85', 'auto1', 3_750_000],
            ['Style', 'ev85x', 'auto1_4x4', 4_050_000],
            ['Sportline', 'ev85', 'auto1', 3_950_000],
            ['Sportline', 'ev85x', 'auto1_4x4', 4_250_000],
        ],
    ],

    // Listed per category in display order (sort_order follows the position in the list).
    'equipment' => [
        'safety' => [
            ['name' => 'Front Assist (kočenje u nuždi)', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Bočni vazdušni jastuci', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Kamera za vožnju unazad', 'levels' => [['O', 25_000], 'S', 'S']],
            ['name' => 'Parking senzori pozadi', 'levels' => [['O', 25_000], 'S', 'S']],
            ['name' => 'Parking senzori spreda i pozadi', 'levels' => [null, ['O', 30_000], 'S']],
            ['name' => 'Adaptivni tempomat (ACC)', 'levels' => [null, ['O', 65_000], 'S'], 'except' => ['fabia']],
        ],
        'comfort' => [
            ['name' => 'Klima uređaj', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Automatska klima (2 zone)', 'levels' => [['O', 35_000], 'S', 'S']],
            ['name' => 'Grejanje prednjih sedišta', 'levels' => [null, ['O', 30_000], 'S']],
            ['name' => 'Bežično punjenje telefona', 'levels' => [null, ['O', 25_000], 'S']],
            ['name' => 'Keyless pristup (KESSY)', 'levels' => [null, ['O', 40_000], 'S']],
            ['name' => 'Električno podešavanje vozačkog sedišta', 'levels' => [null, null, ['O', 50_000]], 'except' => ['fabia']],
            ['name' => 'Toplotna pumpa', 'levels' => [['O', 110_000], ['O', 110_000], 'S'], 'only' => ['enyaq']],
        ],
        'exterior' => [
            ['name' => 'LED prednja svetla', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Matrix LED svetla', 'levels' => [null, ['O', 90_000], 'S'], 'except' => ['fabia']],
            ['name' => 'Alu felne 17"', 'levels' => [['O', 60_000], 'S', 'S']],
            ['name' => 'Panoramski krov', 'levels' => [null, ['O', 110_000], ['O', 110_000]], 'except' => ['fabia']],
            ['name' => 'Kuka za vuču', 'levels' => [['O', 70_000], ['O', 70_000], ['O', 70_000]], 'except' => ['fabia']],
        ],
        'interior' => [
            ['name' => 'Presvlake od veštačke kože', 'levels' => [null, ['O', 80_000], 'S']],
            ['name' => 'Sportska sedišta', 'levels' => [null, null, 'S']],
            ['name' => 'Multifunkcionalni volan', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Ambijentalno osvetljenje', 'levels' => [['O', 20_000], 'S', 'S']],
        ],
        'multimedia' => [
            ['name' => 'Infotainment sa 10" ekranom', 'levels' => [['O', 50_000], 'S', 'S']],
            ['name' => 'Navigacija', 'levels' => [['O', 60_000], 'S', 'S']],
            ['name' => 'Virtualni kokpit', 'levels' => [['O', 40_000], 'S', 'S']],
            ['name' => 'Bežični CarPlay / Android Auto', 'levels' => ['S', 'S', 'S']],
            ['name' => 'Canton audio sistem', 'levels' => [null, ['O', 70_000], ['O', 70_000]], 'except' => ['fabia']],
        ],
        'driving' => [
            ['name' => 'Režimi vožnje (Drive Mode Select)', 'levels' => [null, ['O', 30_000], 'S']],
            ['name' => 'Sportsko vešanje', 'levels' => [null, null, 'S']],
            ['name' => 'Progresivno upravljanje', 'levels' => [null, null, ['O', 40_000]]],
        ],
    ],
];
