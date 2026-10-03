<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\InvalidRealCatalogException;
use App\Support\RealCatalog;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesRealCatalogFiles;
use Tests\TestCase;

/**
 * Every kind of mistake in the real catalog file is reported with a path BEFORE anything is
 * written, both by the seeder and by catalog:validate-real.
 */
class RealCatalogFileTest extends TestCase
{
    use RefreshDatabase, UsesRealCatalogFiles;

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @return list<string> validation errors of the mutated sample
     */
    private function errorsOf(callable $mutate): array
    {
        return RealCatalog::validate($mutate($this->sample()));
    }

    private function assertError(string $expected, callable $mutate): void
    {
        $errors = $this->errorsOf($mutate);

        $this->assertNotEmpty(array_filter($errors, fn (string $error) => str_contains($error, $expected)), "Expected \"$expected\" in: ".implode(' | ', $errors));
    }

    public function test_the_sample_and_the_empty_frame_are_valid(): void
    {
        $this->assertSame([], RealCatalog::validate($this->sample()));
        $this->assertSame([], RealCatalog::validate(RealCatalog::load(database_path('seeders/data/real_catalog.php'))));
    }

    public function test_the_real_catalog_file_of_the_repository_passes_the_same_validation(): void
    {
        // CI blocks a bad real_catalog.php: this is the check catalog:validate-real runs.
        $path = database_path('seeders/data/real_catalog.php');

        $catalog = RealCatalog::load($path);

        $this->assertSame([], RealCatalog::validate($catalog), 'real_catalog.php is invalid: run php artisan catalog:validate-real');

        config(['catalog.real_catalog_path' => $path]);
        $this->artisan('catalog:validate-real')->assertExitCode(0);
    }

    public function test_every_kind_of_mistake_is_reported_with_a_path(): void
    {
        $this->assertError("versions[0]: nepoznat motor 'tsi15'", function (array $c) {
            $c['versions'][0]['engine'] = 'tsi15';

            return $c;
        });
        $this->assertError("versions[1]: nepoznat menjač 'dsg9'", function (array $c) {
            $c['versions'][1]['transmission'] = 'dsg9';

            return $c;
        });
        $this->assertError("versions[0]: nepoznat model 'gamma'", function (array $c) {
            $c['versions'][0]['model'] = 'gamma';

            return $c;
        });
        $this->assertError("versions[0]: nepoznat paket 'Luxury' u modelu 'alfa'", function (array $c) {
            $c['versions'][0]['trim'] = 'Luxury';

            return $c;
        });
        $this->assertError("engines[0].fuel_type: nepoznato gorivo 'steam'", function (array $c) {
            $c['engines'][0]['fuel_type'] = 'steam';

            return $c;
        });
        $this->assertError("transmissions[0].type: nepoznat tip menjača 'cvt'", function (array $c) {
            $c['transmissions'][0]['type'] = 'cvt';

            return $c;
        });
        $this->assertError("transmissions[1].drive: nepoznat pogon '6x6'", function (array $c) {
            $c['transmissions'][1]['drive'] = '6x6';

            return $c;
        });
        $this->assertError("equipment[0].category: nepoznata kategorija opreme 'extras'", function (array $c) {
            $c['equipment'][0]['category'] = 'extras';

            return $c;
        });
        $this->assertError("models[1].categories[1]: nepoznata kategorija 'Terenski'", function (array $c) {
            $c['models'][1]['categories'][1] = 'Terenski';

            return $c;
        });
        $this->assertError('engines[0].power_kw: mora biti ceo broj od 20 do 1000', function (array $c) {
            $c['engines'][0]['power_kw'] = 19;

            return $c;
        });
    }

    public function test_prices_must_be_whole_numbers_of_cents_above_zero(): void
    {
        foreach ([0, -5, 1.5, '1000', null, 99_999_999_999] as $bad) {
            $this->assertError('versions[0].gross_cents: cena mora biti ceo broj centi veći od 0', function (array $c) use ($bad) {
                $c['versions'][0]['gross_cents'] = $bad;

                return $c;
            });
        }
    }

    public function test_standard_has_no_price_and_optional_needs_a_price_of_zero_or_more(): void
    {
        $this->assertError('equipment[0].models.alfa.Basic: standardna oprema ne sme imati cenu', function (array $c) {
            $c['equipment'][0]['models']['alfa']['Basic'] = ['S', 1000];

            return $c;
        });
        foreach ([['O', -1], ['O', 1.5], ['O'], ['O', 1, 2], 'X', 5, ['Z', 100], ['O', '100']] as $i => $bad) {
            $errors = $this->errorsOf(function (array $c) use ($bad) {
                $c['equipment'][0]['models']['alfa']['Basic'] = $bad;

                return $c;
            });

            $this->assertNotEmpty(array_filter($errors, fn (string $e) => str_starts_with($e, 'equipment[0].models.alfa.Basic:')), "case $i should be rejected");
        }
        // Zero is a valid price of a free option.
        $this->assertSame([], $this->errorsOf(function (array $c) {
            $c['equipment'][0]['models']['alfa']['Basic'] = ['O', 0];

            return $c;
        }));
    }

    public function test_equipment_references_must_exist(): void
    {
        $this->assertError('equipment[1].models.gamma: nepoznat model', function (array $c) {
            $c['equipment'][1]['models']['gamma'] = ['Basic' => 'S'];

            return $c;
        });
        $this->assertError("equipment[1].models.alfa.Luxury: nepoznat paket 'Luxury' u modelu 'alfa'", function (array $c) {
            $c['equipment'][1]['models']['alfa']['Luxury'] = 'S';

            return $c;
        });
    }

    public function test_duplicates_are_reported(): void
    {
        $this->assertError('versions[6]: verzija alfa / Basic / e_petrol / t_man je dupla (već je navedena u versions[0])', function (array $c) {
            $c['versions'][] = $c['versions'][0];

            return $c;
        });
        $this->assertError('versions[6]: verzija alfa / BASIC / e_petrol / t_man je dupla', function (array $c) {
            $c['versions'][] = [...$c['versions'][0], 'trim' => 'BASIC'];

            return $c;
        });
        $this->assertError("engines[1].key: ključ 'e_petrol' je dupliran", function (array $c) {
            $c['engines'][1]['key'] = 'e_petrol';

            return $c;
        });
        $this->assertError("transmissions[1].key: ključ 't_man' je dupliran", function (array $c) {
            $c['transmissions'][1]['key'] = 't_man';

            return $c;
        });
        $this->assertError("models[1].slug: oznaka 'alfa' je duplirana", function (array $c) {
            $c['models'][1]['slug'] = 'alfa';

            return $c;
        });
        $this->assertError("models[0].trims[1]: paket 'basic' je dupliran u modelu", function (array $c) {
            $c['models'][0]['trims'][1] = 'basic';

            return $c;
        });
        $this->assertError("categories[1]: kategorija 'PROBNI GRADSKI' je duplirana", function (array $c) {
            $c['categories'][1] = 'PROBNI GRADSKI';

            return $c;
        });
    }

    public function test_the_same_equipment_name_with_another_category_is_reported(): void
    {
        $this->assertError("equipment[4]: naziv 'PROBNA SIGURNOST' već postoji u equipment[0]", function (array $c) {
            $c['equipment'][] = ['name' => 'PROBNA SIGURNOST', 'category' => 'comfort', 'models' => ['alfa' => ['Plus' => 'S']]];

            return $c;
        });
        $this->assertError("equipment[4]: naziv 'Probna sigurnost' već postoji", function (array $c) {
            $c['equipment'][] = ['name' => 'Probna sigurnost', 'category' => 'safety', 'models' => []];

            return $c;
        });
    }

    public function test_meta_is_validated(): void
    {
        foreach ([10001, -1, '2000', 20.5, null] as $rate) {
            $this->assertError('meta.vat_rate_bp: mora biti ceo broj od 0 do 10000', function (array $c) use ($rate) {
                $c['meta']['vat_rate_bp'] = $rate;

                return $c;
            });
        }
        foreach (['2026-13-40', '15.01.2026', '', '2026-1-5'] as $date) {
            $this->assertError('meta.read_on: mora biti datum u obliku YYYY-MM-DD', function (array $c) use ($date) {
                $c['meta']['read_on'] = $date;

                return $c;
            });
        }
        $this->assertError('meta.source: mora biti neprazan tekst', function (array $c) {
            $c['meta']['source'] = '  ';

            return $c;
        });
        $this->assertError('meta: nedostaje', function (array $c) {
            unset($c['meta']);

            return $c;
        });
    }

    public function test_missing_lists_are_reported(): void
    {
        $this->assertError('engines: nedostaje ili nije lista', function (array $c) {
            unset($c['engines']);

            return $c;
        });
    }

    public function test_all_mistakes_are_reported_at_once(): void
    {
        $errors = $this->errorsOf(function (array $c) {
            $c['versions'][0]['engine'] = 'tsi15';
            $c['engines'][0]['fuel_type'] = 'steam';
            $c['versions'][2]['gross_cents'] = 0;

            return $c;
        });

        $this->assertCount(3, $errors);
    }

    public function test_the_seeder_rejects_each_mistake_before_writing_anything(): void
    {
        $mutations = [
            'unknown engine' => function (array $c) {
                $c['versions'][0]['engine'] = 'tsi15';

                return $c;
            },
            'bad enum' => function (array $c) {
                $c['engines'][0]['fuel_type'] = 'steam';

                return $c;
            },
            'price zero' => function (array $c) {
                $c['versions'][0]['gross_cents'] = 0;

                return $c;
            },
            'standard with price' => function (array $c) {
                $c['equipment'][0]['models']['alfa']['Basic'] = ['S', 5];

                return $c;
            },
            'duplicate version' => function (array $c) {
                $c['versions'][] = $c['versions'][0];

                return $c;
            },
            'unknown category' => function (array $c) {
                $c['models'][0]['categories'] = ['Nepoznata'];

                return $c;
            },
            'equipment name in two categories' => function (array $c) {
                $c['equipment'][] = ['name' => 'probna sigurnost', 'category' => 'driving', 'models' => []];

                return $c;
            },
        ];

        foreach ($mutations as $label => $mutate) {
            $this->useCatalog($mutate($this->sample()));

            try {
                $this->seed(RealCatalogSeeder::class);
                $this->fail("$label: the seeder must reject the file");
            } catch (InvalidRealCatalogException $e) {
                $this->assertNotEmpty($e->errors, $label);
            }

            $this->assertNothingWritten();
        }
    }

    // --- catalog:validate-real ---

    public function test_the_validate_command_accepts_a_valid_file_and_prints_the_numbers(): void
    {
        $this->useCatalog($this->sample());

        $this->artisan('catalog:validate-real')
            ->expectsOutputToContain('Izvor: Izmišljeni probni podaci (nije stvaran cenovnik) (pročitano 2026-01-15), PDV 10%.')
            ->expectsOutputToContain('Kategorije: 3, modeli: 2, motori: 3, menjači: 2, verzije: 6, stavke opreme: 4 (unosa u matrici: 12).')
            ->expectsOutputToContain('Fajl je ispravan.')
            ->assertExitCode(0);

        $this->assertNothingWritten();
    }

    public function test_the_validate_command_lists_every_error_and_fails(): void
    {
        $catalog = $this->sample();
        $catalog['versions'][3]['engine'] = 'tsi15';
        $catalog['engines'][0]['fuel_type'] = 'steam';
        $this->useCatalog($catalog);

        $this->artisan('catalog:validate-real')
            ->expectsOutputToContain('Fajl nije ispravan (2 greške/a), ništa nije upisano:')
            ->expectsOutputToContain("versions[3]: nepoznat motor 'tsi15'")
            ->expectsOutputToContain("engines[0].fuel_type: nepoznato gorivo 'steam'")
            ->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_the_validate_command_handles_the_empty_frame_a_missing_file_and_the_path_option(): void
    {
        $this->artisan('catalog:validate-real', ['--path' => database_path('seeders/data/real_catalog.php')])
            ->expectsOutputToContain('Prazan okvir: nema podataka za učitavanje. Fajl je ispravan.')
            ->assertExitCode(0);

        $this->artisan('catalog:validate-real', ['--path' => base_path('missing.php')])
            ->expectsOutputToContain('Fajl sa realnim podacima ne postoji')
            ->assertExitCode(1);

        $sample = base_path('tests/fixtures/real_catalog_sample.php');
        $this->artisan('catalog:validate-real', ['--path' => $sample])->expectsOutputToContain('Fajl je ispravan.')->assertExitCode(0);
    }

    public function test_the_new_log_actions_and_fields_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['catalog.real_seeded', 'catalog.purged', 'catalog.seeded'] as $action) {
            $this->assertNotEmpty($translations["activity.action.$action"] ?? null, $action);
        }
        // Fields of the summary entries (counts per entity).
        foreach (['car_model', 'trim', 'engine', 'transmission', 'version', 'equipment_item', 'trim_equipment', 'category', 'car_model_category'] as $field) {
            $this->assertNotEmpty($translations["activity.field.catalog.$field"] ?? null, $field);
        }
        // The marker is a setting.
        $this->assertNotEmpty($translations['activity.action.setting.created'] ?? null);
        $this->assertNotEmpty($translations['activity.action.setting.deleted'] ?? null);
        $this->assertNull(Setting::where('key', 'catalog_real_seeded_at')->first());
    }
}
