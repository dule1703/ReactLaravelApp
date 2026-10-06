<?php

namespace Tests\Feature;

use Tests\TestCase;

class LangFileTest extends TestCase
{
    public function test_translation_file_has_no_duplicate_keys(): void
    {
        $raw = file_get_contents(base_path('lang/sr_Latn.json'));

        // json_decode silently keeps the last duplicate, so compare raw key lines to the decoded count.
        $rawCount = preg_match_all('/^    "((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $matches);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $duplicates = array_keys(array_filter(array_count_values(array_map('stripcslashes', $matches[1])), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates, 'Duplicate keys in lang/sr_Latn.json');
        $this->assertSame(count($decoded), $rawCount);
    }
}
