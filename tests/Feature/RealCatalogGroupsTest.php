<?php

namespace Tests\Feature;

use App\Enums\EquipmentAvailability;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Support\InvalidRealCatalogException;
use App\Support\OptionGroupRule;
use App\Support\RealCatalog;
use App\Support\Vat;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesRealCatalogFiles;
use Tests\TestCase;

/**
 * Option groups in the real catalog file: validation (also what catalog:validate-real prints) and
 * the seeder. Sample equipment indexes: 3 = wheels 16", 4 = wheels 17", 5 = wheels 18", 6 = white,
 * 7 = red (colors, with swatches).
 */
class RealCatalogGroupsTest extends TestCase
{
    use RefreshDatabase, UsesRealCatalogFiles;

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @return list<string>
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

    // --- validation ---

    public function test_the_sample_with_groups_is_valid(): void
    {
        $this->assertSame([], RealCatalog::validate($this->sample()));
        $this->assertSame(2, RealCatalog::counts($this->sample())['groups']);
    }

    public function test_group_definitions_are_validated(): void
    {
        $this->assertError("groups[0].selection: nepoznat izbor 'both'", function (array $c) {
            $c['groups'][0]['selection'] = 'both';

            return $c;
        });
        $this->assertError("groups[1].category: nepoznata kategorija opreme 'extras'", function (array $c) {
            $c['groups'][1]['category'] = 'extras';

            return $c;
        });
        $this->assertError("groups[1].name: grupa 'PROBNI TOČKOVI' je duplirana", function (array $c) {
            $c['groups'][1]['name'] = 'PROBNI TOČKOVI';

            return $c;
        });
        $this->assertError('groups[0].name: naziv mora biti neprazan tekst', function (array $c) {
            $c['groups'][0]['name'] = ' ';

            return $c;
        });
        $this->assertError('groups[0].swatch: mora biti true ili false', function (array $c) {
            $c['groups'][0]['swatch'] = 'yes';

            return $c;
        });
        $this->assertError('groups: nedostaje ili nije lista', function (array $c) {
            unset($c['groups']);

            return $c;
        });
    }

    public function test_an_unknown_group_is_reported_with_the_path(): void
    {
        $this->assertError("equipment[3].group: nepoznata grupa 'Nepoznata' (mora biti navedena u listi groups)", function (array $c) {
            $c['equipment'][3]['group'] = 'Nepoznata';

            return $c;
        });
    }

    public function test_the_category_of_an_item_must_match_the_category_of_its_group(): void
    {
        $this->assertError("equipment[3].category: kategorija 'safety' se ne slaže sa kategorijom grupe 'Probni točkovi' (exterior)", function (array $c) {
            $c['equipment'][3]['category'] = 'safety';

            return $c;
        });
    }

    public function test_the_swatch_is_checked(): void
    {
        foreach (['#FFF', 'FFFFFF', '#GGGGGG', '#FFFFFFF', 7] as $bad) {
            $this->assertError('equipment[6].swatch_hex: mora biti boja u obliku #RRGGBB', function (array $c) use ($bad) {
                $c['equipment'][6]['swatch_hex'] = $bad;

                return $c;
            });
        }
        // On an item of a group without swatches, and on an item without a group.
        $this->assertError('equipment[3].swatch_hex: dozvoljeno samo za stavke grupe sa uzorcima boja', function (array $c) {
            $c['equipment'][3]['swatch_hex'] = '#FFFFFF';

            return $c;
        });
        $this->assertError('equipment[0].swatch_hex: dozvoljeno samo za stavke grupe sa uzorcima boja', function (array $c) {
            $c['equipment'][0]['swatch_hex'] = '#FFFFFF';

            return $c;
        });
        // Lowercase hex digits and no swatch at all are fine.
        $this->assertSame([], $this->errorsOf(function (array $c) {
            $c['equipment'][6]['swatch_hex'] = '#abcdef';
            unset($c['equipment'][7]['swatch_hex']);

            return $c;
        }));
    }

    public function test_a_single_group_needs_exactly_one_standard_item_per_trim(): void
    {
        // Two standard items of the wheels on alfa / Basic.
        $this->assertError('groups[Probni točkovi] alfa / Basic: više standardnih stavki (Probni točkovi 16", Probni točkovi 17"), a mora biti tačno jedna', function (array $c) {
            $c['equipment'][4]['models']['alfa']['Basic'] = 'S';

            return $c;
        });
        // No standard item on alfa / Basic (the 16" becomes a surcharge).
        $this->assertError('groups[Probni točkovi] alfa / Basic: nijedna standardna stavka, a mora biti tačno jedna', function (array $c) {
            $c['equipment'][3]['models']['alfa']['Basic'] = ['O', 0];

            return $c;
        });
        // Colors on beta / Top: the only standard color is removed.
        $this->assertError('groups[Probne boje] beta / Top: nijedna standardna stavka', function (array $c) {
            unset($c['equipment'][6]['models']['beta']['Top']);

            return $c;
        });
    }

    public function test_every_problem_is_reported_not_only_the_first(): void
    {
        $errors = $this->errorsOf(function (array $c) {
            $c['equipment'][4]['models']['alfa']['Basic'] = 'S';          // two standard
            $c['equipment'][6]['models']['beta']['Top'] = ['O', 100];      // no standard color on beta / Top
            $c['equipment'][3]['group'] = 'Nepoznata';                     // unknown group

            return $c;
        });

        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    public function test_a_trim_without_entries_does_not_offer_the_group_and_that_is_valid(): void
    {
        // Wheels are not offered at all on alfa / Plus.
        $this->assertSame([], $this->errorsOf(function (array $c) {
            unset($c['equipment'][4]['models']['alfa']['Plus'], $c['equipment'][5]['models']['alfa']['Plus']);

            return $c;
        }));
        // A group without any entry anywhere.
        $this->assertSame([], $this->errorsOf(function (array $c) {
            $c['groups'][] = ['name' => 'Prazna grupa', 'selection' => 'single', 'category' => 'interior'];

            return $c;
        }));
    }

    public function test_a_multiple_group_has_no_standard_item_rule(): void
    {
        $this->assertSame([], $this->errorsOf(function (array $c) {
            $c['groups'][0]['selection'] = 'multiple';
            $c['equipment'][4]['models']['alfa']['Basic'] = 'S';   // a second standard item is fine
            $c['equipment'][3]['models']['alfa']['Basic'] = ['O', 0];

            return $c;
        }));
    }

    public function test_the_price_rules_still_apply_to_items_of_a_group(): void
    {
        $this->assertError('equipment[4].models.alfa.Plus: standardna oprema ne sme imati cenu', function (array $c) {
            $c['equipment'][4]['models']['alfa']['Plus'] = ['S', 5];

            return $c;
        });
        $this->assertError('equipment[5].models.alfa.Basic: cena dodatne opreme mora biti ceo broj centi 0 ili veći', function (array $c) {
            $c['equipment'][5]['models']['alfa']['Basic'] = ['O', -1];

            return $c;
        });
    }

    public function test_the_validate_command_reports_group_errors_with_paths(): void
    {
        $catalog = $this->sample();
        $catalog['equipment'][4]['models']['alfa']['Basic'] = 'S';
        $catalog['equipment'][5]['group'] = 'Nepoznata';
        $this->useCatalog($catalog);

        $this->artisan('catalog:validate-real')
            ->expectsOutputToContain('Fajl nije ispravan (2 greške/a), ništa nije upisano:')
            ->expectsOutputToContain("equipment[5].group: nepoznata grupa 'Nepoznata'")
            ->expectsOutputToContain('groups[Probni točkovi] alfa / Basic: više standardnih stavki')
            ->assertExitCode(1);

        $this->assertNothingWritten();
    }

    public function test_the_validate_command_prints_the_number_of_groups(): void
    {
        $this->useCatalog($this->sample());

        $this->artisan('catalog:validate-real')
            ->expectsOutputToContain('Kategorije: 3, grupe opcija: 2, modeli: 2, motori: 3, menjači: 2, verzije: 6, stavke opreme: 9 (unosa u matrici: 33).')
            ->expectsOutputToContain('Fajl je ispravan.')
            ->assertExitCode(0);
    }

    public function test_the_seeder_rejects_a_bad_group_before_writing_anything(): void
    {
        $catalog = $this->sample();
        $catalog['equipment'][6]['models']['beta']['Top'] = ['O', 100];
        $this->useCatalog($catalog);

        try {
            $this->seed(RealCatalogSeeder::class);
            $this->fail('A bad group must be rejected.');
        } catch (InvalidRealCatalogException $e) {
            $this->assertStringContainsString('groups[Probne boje] beta / Top: nijedna standardna stavka', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    // --- seeder ---

    public function test_the_seeder_creates_the_groups_and_links_the_items(): void
    {
        $this->useCatalog($this->sample());

        $this->seed(RealCatalogSeeder::class);

        $wheels = OptionGroup::where('slug', 'probni-tockovi')->sole();
        $this->assertSame('Probni točkovi', $wheels->name);
        $this->assertSame('single', $wheels->selection->value);
        $this->assertSame('exterior', $wheels->category->value);
        $this->assertFalse($wheels->uses_swatch);
        $this->assertSame(1, $wheels->sort_order);
        $this->assertSame(
            ['Probni točkovi 16"', 'Probni točkovi 17"', 'Probni točkovi 18"'],
            $wheels->items()->orderBy('name')->pluck('name')->all(),
        );

        $colors = OptionGroup::where('slug', 'probne-boje')->sole();
        $this->assertTrue($colors->uses_swatch);
        $this->assertSame(['#C62828', '#FFFFFF'], $colors->items()->orderBy('swatch_hex')->pluck('swatch_hex')->all());

        // The item that is not in a group stays an independent extra.
        $this->assertNull(EquipmentItem::where('name', 'Probna sigurnost')->sole()->group_id);
        $this->assertNull(EquipmentItem::where('name', 'Probna sigurnost')->sole()->swatch_hex);
        $this->assertSame(2, OptionGroup::count());
    }

    public function test_the_seeded_surcharges_are_net_values_of_the_gross_differences(): void
    {
        $this->useCatalog($this->sample());
        $this->seed(RealCatalogSeeder::class);

        $row = fn (string $item, string $model, string $trim) => TrimEquipment::whereHas('equipmentItem', fn ($q) => $q->where('name', $item))
            ->whereHas('trim', fn ($q) => $q->where('name', $trim)->whereHas('carModel', fn ($m) => $m->where('slug', $model)))
            ->first();

        $surcharge = $row('Probni točkovi 17"', 'alfa', 'Basic');
        $this->assertSame(EquipmentAvailability::Optional, $surcharge->availability);
        $this->assertSame(Vat::netFromGross(44_000, 1000), $surcharge->price_cents);

        $standard = $row('Probni točkovi 17"', 'alfa', 'Plus');
        $this->assertSame(EquipmentAvailability::Standard, $standard->availability);
        $this->assertNull($standard->price_cents);

        // Not available on a trim: no row.
        $this->assertNull($row('Probni točkovi 16"', 'alfa', 'Plus'));
        $this->assertNull($row('Probni točkovi 17"', 'beta', 'Top'));
    }

    public function test_every_seeded_group_passes_the_rule_on_every_trim(): void
    {
        $this->useCatalog($this->sample());
        $this->seed(RealCatalogSeeder::class);

        foreach (OptionGroup::all() as $group) {
            foreach (Trim::all() as $trim) {
                $this->assertSame([], OptionGroupRule::problemsFor($group, $trim), "{$group->name} on {$trim->name}");
            }
        }
    }

    public function test_the_seeder_with_groups_is_idempotent_and_keeps_manual_changes(): void
    {
        $this->useCatalog($this->sample());
        $this->seed(RealCatalogSeeder::class);
        $counts = $this->catalogCounts();

        $wheels = OptionGroup::where('slug', 'probni-tockovi')->sole();
        $wheels->update(['is_active' => false, 'sort_order' => 9]);
        $item = EquipmentItem::where('name', 'Probna crvena')->sole();
        $item->update(['swatch_hex' => '#111111']);

        $this->seed(RealCatalogSeeder::class);

        $this->assertSame($counts, $this->catalogCounts());
        $this->assertFalse($wheels->fresh()->is_active);
        $this->assertSame(9, $wheels->fresh()->sort_order);
        $this->assertSame('#111111', $item->fresh()->swatch_hex);
    }

    public function test_an_existing_item_keeps_its_group_assignment(): void
    {
        // Attributes apply only when an item is created: an item that already exists (without a
        // group) is not moved into the group by a later seed.
        EquipmentItem::factory()->create(['name' => 'Probni točkovi 17"', 'category' => 'exterior']);
        $this->useCatalog($this->sample());

        $this->seed(RealCatalogSeeder::class);

        $this->assertNull(EquipmentItem::where('name', 'Probni točkovi 17"')->sole()->group_id);
        $this->assertSame(1, EquipmentItem::where('name', 'Probni točkovi 17"')->count());
    }
}
