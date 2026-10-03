<?php

namespace App\Support;

use RuntimeException;

/**
 * The real catalog file did not pass validation; nothing was written.
 */
class InvalidRealCatalogException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors, string $path)
    {
        parent::__construct(
            "Fajl $path nije ispravan (".count($errors).' greške/a), ništa nije upisano:'.PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $errors),
        );
    }
}
