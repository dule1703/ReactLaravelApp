<?php

return [
    // Real catalog data (see the format comments in the file). Tests point this at a sample file.
    // An empty CATALOG_REAL_PATH counts as not set (phpunit.xml empties it so tests never read a private file).
    'real_catalog_path' => env('CATALOG_REAL_PATH') ?: database_path('seeders/data/real_catalog.php'),

    // How far `php artisan catalog:purge-demo` may go: off (refuses), demo (only before real data
    // was loaded) or all (also real data, with --include-real). Never set it on production.
    'purge' => env('CATALOG_PURGE', 'off'),

    // Tables of the offers (4.1): the purge command refuses while any of them has rows. Keep it
    // in sync with the migrations when an offer table is added.
    'offer_tables' => ['offers', 'offer_items', 'offer_item_options'],
];
