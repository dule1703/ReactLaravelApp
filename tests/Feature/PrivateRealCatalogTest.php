<?php

namespace Tests\Feature;

use App\Models\CarModel;
use App\Models\Version;
use App\Support\RealCatalog;
use App\Support\RealCatalogLocationException;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesRealCatalogFiles;
use Tests\TestCase;

/**
 * The repository is PUBLIC: real catalog data lives in a private, git-ignored file (or outside the
 * repository) and must never be committed.
 */
class PrivateRealCatalogTest extends TestCase
{
    use RefreshDatabase, UsesRealCatalogFiles;

    /** @var list<string> files created inside the repository tree by a test */
    private array $created = [];

    private bool $createdPrivateDirectory = false;

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }

        $private = base_path(RealCatalog::PRIVATE_DIRECTORY);
        if ($this->createdPrivateDirectory && is_dir($private) && count(scandir($private)) === 2) {
            @rmdir($private);
        }

        parent::tearDown();
    }

    /**
     * Write a catalog file inside the repository folder (and remember it for clean-up).
     *
     * @param  array<string, mixed>  $catalog
     */
    private function writeInRepository(string $relativePath, array $catalog): string
    {
        $path = base_path($relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);

            if ($directory === base_path(RealCatalog::PRIVATE_DIRECTORY)) {
                $this->createdPrivateDirectory = true;
            }
        }

        file_put_contents($path, '<?php return '.var_export($catalog, true).';');
        $this->created[] = $path;

        return $path;
    }

    // --- the repository holds no real data ---

    public function test_the_tracked_real_catalog_file_is_an_empty_frame(): void
    {
        // CI fails when somebody writes data into the file that is committed.
        $catalog = RealCatalog::load(database_path('seeders/data/real_catalog.php'));

        $this->assertTrue(RealCatalog::isEmpty($catalog), 'database/seeders/data/real_catalog.php must stay an empty frame: real data belongs in a private file.');
        $this->assertSame([], RealCatalog::validate($catalog));
    }

    public function test_the_default_path_is_the_empty_frame_in_the_repository(): void
    {
        $this->assertSame(
            database_path('seeders/data/real_catalog.php'),
            config('catalog.real_catalog_path'),
            'The default must point at the empty frame; a private file is chosen with CATALOG_REAL_PATH.',
        );
    }

    public function test_no_file_in_the_private_directory_is_tracked_by_git(): void
    {
        $git = new Process(['git', 'ls-files', '--', RealCatalog::PRIVATE_DIRECTORY], base_path());

        try {
            $git->run();
        } catch (\Throwable) {
            $this->markTestSkipped('git is not available.');
        }

        if (! $git->isSuccessful()) {
            $this->markTestSkipped('Not a git checkout or git is not available.');
        }

        // Catches "git add -f": the .gitignore alone cannot.
        $this->assertSame('', trim($git->getOutput()), 'Files in '.RealCatalog::PRIVATE_DIRECTORY.'/ must never be committed.');
    }

    public function test_gitignore_lists_the_private_directory(): void
    {
        $lines = array_map('trim', file(base_path('.gitignore')));

        $this->assertContains('/'.RealCatalog::PRIVATE_DIRECTORY.'/', $lines);
    }

    public function test_the_readme_says_the_project_is_not_affiliated(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertStringContainsString('nije povezan sa Škoda Auto', $readme);
        $this->assertStringContainsString('izmišljene i neslužbene', $readme);
    }

    public function test_the_guide_exists_and_covers_the_server_setup(): void
    {
        $guide = file_get_contents(base_path('docs/real-catalog.md'));

        foreach (['CATALOG_REAL_PATH', 'CATALOG_PURGE=off', '640', 'shared/real_catalog.php', 'ličnu', 'catalog:validate-real', 'catalog:purge-demo', 'RealCatalogSeeder'] as $needle) {
            $this->assertStringContainsString($needle, $guide, $needle);
        }
    }

    // --- where a file lives ---

    public function test_the_location_of_a_file(): void
    {
        $sample = $this->sample();

        $this->assertSame('outside', RealCatalog::location($this->useCatalog($sample)));
        $this->assertSame('repository', RealCatalog::location($this->writeInRepository('storage/framework/testing/location-'.uniqid().'.php', $sample)));
        $this->assertSame('private', RealCatalog::location($this->writeInRepository(RealCatalog::PRIVATE_DIRECTORY.'/location-'.uniqid().'.php', $sample)));
        $this->assertSame('repository', RealCatalog::location(database_path('seeders/data/real_catalog.php')));
        // A file that does not exist cannot be judged (load() reports it).
        $this->assertSame('outside', RealCatalog::location(base_path('does/not/exist.php')));
    }

    public function test_the_directory_comparison_is_exact(): void
    {
        $this->assertTrue(RealCatalog::isInside('/app/database/private/real.php', '/app/database/private'));
        $this->assertTrue(RealCatalog::isInside('/app/database/private/sub/real.php', '/app/database/private/'));
        // A sibling that merely starts with the same letters is NOT inside.
        $this->assertFalse(RealCatalog::isInside('/app/database/private-evil/real.php', '/app/database/private'));
        $this->assertFalse(RealCatalog::isInside('/app/database/privatereal.php', '/app/database/private'));
        $this->assertFalse(RealCatalog::isInside('/elsewhere/private/real.php', '/app/database/private'));
        // Backslashes (Windows) are the same as slashes.
        $this->assertTrue(RealCatalog::isInside('C:\\app\\database\\private\\real.php', 'C:\\app\\database\\private'));
    }

    // --- the seeder ---

    public function test_the_seeder_refuses_a_non_empty_file_inside_the_repository_outside_private(): void
    {
        $path = $this->writeInRepository('storage/framework/testing/real-'.uniqid().'.php', $this->sample());
        config(['catalog.real_catalog_path' => $path]);

        try {
            $this->seed(RealCatalogSeeder::class);
            $this->fail('A file inside the repository must be refused.');
        } catch (RealCatalogLocationException $e) {
            $this->assertStringContainsString('Odbijeno: fajl sa realnim podacima', $e->getMessage());
            $this->assertStringContainsString('unutar repozitorijuma', $e->getMessage());
            $this->assertStringContainsString('database/seeders/data/private/real_catalog.php', $e->getMessage());
            $this->assertStringContainsString('CATALOG_REAL_PATH', $e->getMessage());
            $this->assertStringContainsString('JAVAN', $e->getMessage());
            $this->assertStringContainsString('Ništa nije upisano.', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    public function test_the_location_is_judged_before_the_content(): void
    {
        $bad = $this->sample();
        $bad['versions'][0]['engine'] = 'unknown';
        config(['catalog.real_catalog_path' => $this->writeInRepository('storage/framework/testing/bad-'.uniqid().'.php', $bad)]);

        $this->expectException(RealCatalogLocationException::class);

        $this->seed(RealCatalogSeeder::class);
    }

    public function test_the_seeder_accepts_a_file_in_the_private_directory(): void
    {
        config(['catalog.real_catalog_path' => $this->writeInRepository(RealCatalog::PRIVATE_DIRECTORY.'/seed-'.uniqid().'.php', $this->sample())]);

        $this->seed(RealCatalogSeeder::class);

        $this->assertSame(6, Version::count());
        $this->assertSame(2, CarModel::count());
    }

    public function test_the_seeder_accepts_a_file_outside_the_repository(): void
    {
        $path = $this->useCatalog($this->sample());

        $this->assertSame('outside', RealCatalog::location($path));

        $this->seed(RealCatalogSeeder::class);

        $this->assertSame(6, Version::count());
    }

    public function test_the_empty_frame_inside_the_repository_is_not_refused(): void
    {
        config(['catalog.real_catalog_path' => database_path('seeders/data/real_catalog.php')]);

        $this->artisan('db:seed', ['--class' => RealCatalogSeeder::class])
            ->expectsOutputToContain('prazan okvir')
            ->assertExitCode(0);

        $this->assertNothingWritten();
    }

    // --- catalog:validate-real ---

    public function test_validate_warns_about_a_non_empty_file_inside_the_repository(): void
    {
        $this->artisan('catalog:validate-real', ['--path' => 'tests/fixtures/real_catalog_sample.php'])
            ->expectsOutputToContain('Upozorenje: fajl sa podacima je unutar repozitorijuma, a nije u database/seeders/data/private/.')
            ->expectsOutputToContain('Fajl je ispravan.')
            ->assertExitCode(0);
    }

    public function test_the_warning_says_what_to_do(): void
    {
        $this->artisan('catalog:validate-real', ['--path' => base_path('tests/fixtures/real_catalog_sample.php')])
            ->expectsOutputToContain('Premestite fajl u database/seeders/data/private/real_catalog.php (ignoriše se u gitu) ili van repozitorijuma')
            ->assertExitCode(0);
    }

    public function test_the_warning_is_only_a_warning_an_invalid_file_still_fails_for_its_errors(): void
    {
        $bad = $this->sample();
        $bad['versions'][0]['engine'] = 'unknown';
        $path = $this->writeInRepository('storage/framework/testing/bad-'.uniqid().'.php', $bad);

        $this->artisan('catalog:validate-real', ['--path' => $path])
            ->expectsOutputToContain('Upozorenje: fajl sa podacima je unutar repozitorijuma')
            ->expectsOutputToContain("versions[0]: nepoznat motor 'unknown'")
            ->assertExitCode(1);
    }

    public function test_there_is_no_warning_outside_the_repository_in_private_or_for_the_empty_frame(): void
    {
        $this->useCatalog($this->sample());
        $this->artisan('catalog:validate-real')
            ->doesntExpectOutputToContain('Upozorenje')
            ->expectsOutputToContain('Fajl je ispravan.')
            ->assertExitCode(0);

        $private = $this->writeInRepository(RealCatalog::PRIVATE_DIRECTORY.'/warn-'.uniqid().'.php', $this->sample());
        $this->artisan('catalog:validate-real', ['--path' => $private])
            ->doesntExpectOutputToContain('Upozorenje')
            ->expectsOutputToContain('Fajl je ispravan.')
            ->assertExitCode(0);

        $this->artisan('catalog:validate-real', ['--path' => database_path('seeders/data/real_catalog.php')])
            ->doesntExpectOutputToContain('Upozorenje')
            ->expectsOutputToContain('Prazan okvir')
            ->assertExitCode(0);
    }
}
