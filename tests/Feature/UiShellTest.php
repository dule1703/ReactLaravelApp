<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_uses_serbian_latin_locale_and_belgrade_timezone(): void
    {
        $this->assertSame('sr_Latn', config('app.locale'));
        $this->assertSame('Europe/Belgrade', config('app.timezone'));
        $this->assertSame('Početna', __('Dashboard'));
    }

    public function test_home_page_does_not_leak_framework_or_php_versions(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Welcome')
            ->missing('laravelVersion')
            ->missing('phpVersion'));
    }

    public function test_validation_messages_are_in_serbian(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email' => 'Polje email adresa je obavezno.']);
    }

    public function test_translation_file_is_valid_json_with_non_empty_values(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($translations as $key => $value) {
            $this->assertNotSame('', trim($value), "Empty translation for [$key]");
        }
    }

    public function test_logo_and_favicon_exist(): void
    {
        $this->assertFileExists(public_path('images/logo.png'));
        $this->assertFileExists(public_path('images/favicon.png'));
    }
}
