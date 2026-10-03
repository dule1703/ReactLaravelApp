<?php

namespace App\Support;

/**
 * Safe LIKE patterns. Use with an explicit escape character, which behaves the same on MySQL
 * and SQLite (a backslash does not):
 *
 *     ->whereRaw("name like ? escape '!'", [Like::contains($term)])
 */
final class Like
{
    /**
     * "%term%" with "!", "%" and "_" in the term escaped by "!".
     */
    public static function contains(string $term): string
    {
        return '%'.preg_replace('/[!%_]/', '!$0', $term).'%';
    }
}
