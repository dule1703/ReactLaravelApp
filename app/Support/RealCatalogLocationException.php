<?php

namespace App\Support;

use RuntimeException;

/**
 * A non-empty real catalog file lies inside the (public) repository but outside the private
 * directory, so it could end up in git. Nothing was written.
 */
class RealCatalogLocationException extends RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct(
            "Odbijeno: fajl sa realnim podacima ($path) je unutar repozitorijuma, a nije u ".RealCatalog::PRIVATE_DIRECTORY.'/. '
            .RealCatalog::locationAdvice().' Ništa nije upisano.',
        );
    }
}
