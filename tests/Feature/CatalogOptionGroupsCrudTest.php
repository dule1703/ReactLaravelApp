<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CatalogOptionGroupsCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function as(): static
    {
        return $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Točkovi', 'category' => 'exterior', 'selection' => 'single', 'uses_swatch' => false], $overrides);
    }

    private function item(OptionGroup $group, array $attributes = []): EquipmentItem
    {
        return EquipmentItem::factory()->create($attributes + ['group_id' => $group->id, 'category' => $group->category]);
    }

    private function line(EquipmentItem $item, string $availability, ?int $price = null, ?Trim $trim = null): TrimEquipment
    {
        return TrimEquipment::create([
            'trim_id' => ($trim ?? Trim::factory()->create())->id,
            'equipment_item_id' => $item->id,
            'availability' => $availability,
            'price_cents' => $price,
        ]);
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $group = OptionGroup::factory()->create();
        $requests = [
            ['get', '/admin/catalog/option-groups'], ['post', '/admin/catalog/option-groups'],
            ['patch', "/admin/catalog/option-groups/{$group->id}"], ['patch', "/admin/catalog/option-groups/{$group->id}/active"],
            ['delete', "/admin/catalog/option-groups/{$group->id}"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        $this->assertNotNull($group->fresh());
    }

    // --- list ---

    public function test_the_list_shows_the_number_of_items_and_swatch_items(): void
    {
        $colors = OptionGroup::factory()->swatch()->create(['name' => 'Boje']);
        $this->item($colors, ['swatch_hex' => '#FFFFFF']);
        $this->item($colors);
        OptionGroup::factory()->create(['name' => 'Točkovi']);

        $this->as()->get('/admin/catalog/option-groups')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/OptionGroups')
            ->has('items.data', 2)
            ->where('items.last_page', 1)
            ->where('items.data.0.name', 'Boje')
            ->where('items.data.0.items_count', 2)
            ->where('items.data.0.swatch_items_count', 1)
            ->where('items.data.0.uses_swatch', true)
            ->where('items.data.1.items_count', 0));

        $this->as()->get('/admin/catalog/option-groups?q=toč')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)->where('items.data.0.name', 'Točkovi'));
    }

    // --- creating ---

    public function test_a_group_is_created_with_a_generated_slug_and_the_next_order(): void
    {
        OptionGroup::factory()->create(['sort_order' => 4]);

        $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => 'Boje karoserije', 'uses_swatch' => true]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Dodato.');

        $group = OptionGroup::where('name', 'Boje karoserije')->sole();
        $this->assertSame('boje-karoserije', $group->slug);
        $this->assertSame(5, $group->sort_order);
        $this->assertTrue($group->uses_swatch);
        $this->assertTrue($group->is_active);
        $this->assertSame('single', $group->selection->value);
        $this->assertSame(1, ActivityLog::where('action', 'option_group.created')->where('subject_id', $group->id)->count());
    }

    public function test_slugs_are_unique_and_never_change(): void
    {
        $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => 'A B']));
        $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => 'A-B']));
        $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => 'A  B']));

        $this->assertEqualsCanonicalizing(['a-b', 'a-b-2', 'a-b-3'], OptionGroup::pluck('slug')->all());

        $group = OptionGroup::where('slug', 'a-b')->sole();
        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => 'Potpuno drugo ime', 'slug' => 'hacked']));

        $this->assertSame('a-b', $group->fresh()->slug);
        $this->assertSame('Potpuno drugo ime', $group->fresh()->name);
    }

    public function test_the_name_is_unique_without_regard_to_case(): void
    {
        OptionGroup::factory()->create(['name' => 'Točkovi']);

        // (SQLite lowercases ASCII only, so a capital non-ASCII letter such as "Č" is not tested here.)
        foreach (['Točkovi', 'točkovi', ' točkovi '] as $name) {
            $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => $name]))
                ->assertSessionHasErrors(['name' => 'Grupa opcija sa tim nazivom već postoji.']);
        }

        // ASCII case differences are certain to be caught on every database.
        OptionGroup::factory()->create(['name' => 'Boje']);
        foreach (['boje', 'BOJE'] as $name) {
            $this->as()->post('/admin/catalog/option-groups', $this->payload(['name' => $name]))->assertSessionHasErrors('name');
        }

        $group = OptionGroup::where('name', 'Boje')->sole();
        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => 'BOJE']))->assertSessionHasNoErrors();
        $this->assertSame('BOJE', $group->fresh()->name);
    }

    public function test_input_is_validated(): void
    {
        foreach ([
            ['name' => ''], ['name' => str_repeat('a', 101)], ['name' => "Ba\x00d"],
            ['category' => 'nonsense'], ['category' => ''], ['selection' => 'both'], ['selection' => ''],
            ['sort_order' => '65536'], ['sort_order' => '-1'], ['uses_swatch' => 'maybe'],
        ] as $bad) {
            $this->as()->post('/admin/catalog/option-groups', $this->payload($bad));

            $this->assertTrue(session('errors')?->any() ?? false, 'Expected errors for '.json_encode($bad));
        }

        $this->assertSame(0, OptionGroup::count());
    }

    // --- changing a group ---

    public function test_the_category_of_an_empty_group_changes_without_confirmation(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => $group->name, 'category' => 'interior']))
            ->assertSessionHasNoErrors();

        $this->assertSame('interior', $group->fresh()->category->value);
    }

    public function test_the_category_of_a_group_with_items_needs_a_confirmation(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);
        $items = [$this->item($group), $this->item($group)];
        $payload = $this->payload(['name' => $group->name, 'category' => 'interior']);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $payload)
            ->assertSessionHasErrors(['category' => 'Promena kategorije menja i kategoriju stavki grupe (2); potvrdite promenu.']);

        $this->assertSame('exterior', $group->fresh()->category->value);
        $this->assertSame('exterior', $items[0]->fresh()->category->value);
    }

    public function test_a_confirmed_category_change_updates_the_group_and_then_all_its_items_in_one_transaction(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);
        $items = [$this->item($group), $this->item($group), $this->item($group)];

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => $group->name, 'category' => 'interior', 'confirm_category_change' => true]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Sačuvano.');

        $this->assertSame('interior', $group->fresh()->category->value);
        foreach ($items as $item) {
            $this->assertSame('interior', $item->fresh()->category->value);
        }

        // Every change went through the models: the group first, then the items.
        $groupLog = ActivityLog::where('action', 'option_group.updated')->where('subject_id', $group->id)->sole();
        $itemLogs = ActivityLog::where('action', 'equipment_item.updated')->whereIn('subject_id', array_map(fn ($item) => $item->id, $items))->get();
        $this->assertCount(3, $itemLogs);
        $this->assertEquals(['old' => 'exterior', 'new' => 'interior'], $groupLog->changes['category']);
        $this->assertTrue($itemLogs->every(fn ($log) => $log->id > $groupLog->id));
    }

    public function test_a_failure_while_changing_the_items_rolls_the_group_back(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);
        $items = [$this->item($group), $this->item($group), $this->item($group)];

        $calls = 0;
        EquipmentItem::updating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('item update failed');
            }
        });

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => $group->name, 'category' => 'interior', 'confirm_category_change' => true]))
            ->assertStatus(500);

        $this->assertSame('exterior', $group->fresh()->category->value);
        foreach ($items as $item) {
            $this->assertSame('exterior', $item->fresh()->category->value);
        }
        $this->assertSame(0, ActivityLog::where('action', 'option_group.updated')->where('subject_id', $group->id)->count());
    }

    public function test_multiple_to_single_needs_exactly_one_standard_item_on_every_trim(): void
    {
        $group = OptionGroup::factory()->multiple()->create(['category' => 'exterior']);
        [$a, $b] = [$this->item($group, ['name' => 'A']), $this->item($group, ['name' => 'B'])];
        $trim = Trim::factory()->create();
        $this->line($a, 'standard', null, $trim);
        $this->line($b, 'standard', null, $trim);   // two standard items on one trim
        $payload = $this->payload(['name' => $group->name, 'selection' => 'single']);

        $response = $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $payload);
        $response->assertSessionHasErrors('selection');
        $this->assertStringContainsString('Linije (1) nemaju tačno jednu standardnu stavku ove grupe', session('errors')->first('selection'));
        $this->assertStringContainsString($trim->carModel->name.' / '.$trim->name, session('errors')->first('selection'));
        $this->assertSame('multiple', $group->fresh()->selection->value);

        // Demote one of them: the final state is valid and the change is accepted.
        TrimEquipment::where('equipment_item_id', $b->id)->first()->update(['availability' => 'optional', 'price_cents' => 5_000]);
        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('single', $group->fresh()->selection->value);
    }

    public function test_multiple_to_single_is_rejected_for_a_trim_without_a_standard_item(): void
    {
        $group = OptionGroup::factory()->multiple()->create();
        $this->line($this->item($group), 'optional', 100);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => $group->name, 'selection' => 'single']))
            ->assertSessionHasErrors('selection');
    }

    public function test_multiple_to_single_is_fine_for_a_group_without_lines_and_single_to_multiple_is_always_fine(): void
    {
        $empty = OptionGroup::factory()->multiple()->create(['name' => 'Prazna']);
        $this->as()->patch("/admin/catalog/option-groups/{$empty->id}", $this->payload(['name' => 'Prazna', 'selection' => 'single']))->assertSessionHasNoErrors();
        $this->assertSame('single', $empty->fresh()->selection->value);

        $single = OptionGroup::factory()->create(['name' => 'Jedno']);
        $trim = Trim::factory()->create();
        $this->line($this->item($single), 'standard', null, $trim);
        $this->as()->patch("/admin/catalog/option-groups/{$single->id}", $this->payload(['name' => 'Jedno', 'selection' => 'multiple']))->assertSessionHasNoErrors();
        $this->assertSame('multiple', $single->fresh()->selection->value);
    }

    public function test_swatches_cannot_be_turned_off_while_an_item_has_one(): void
    {
        $group = OptionGroup::factory()->swatch()->create();
        $withSwatch = $this->item($group, ['swatch_hex' => '#C62828']);
        $this->item($group, ['swatch_hex' => '#FFFFFF']);
        $this->item($group);
        $payload = $this->payload(['name' => $group->name, 'uses_swatch' => false]);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $payload)
            ->assertSessionHasErrors(['uses_swatch' => 'Stavke sa uzorkom boje: 2; prvo uklonite uzorke.']);
        $this->assertTrue($group->fresh()->uses_swatch);

        $withSwatch->update(['swatch_hex' => null]);
        EquipmentItem::where('group_id', $group->id)->update(['swatch_hex' => null]);   // test setup only
        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $payload)->assertSessionHasNoErrors();
        $this->assertFalse($group->fresh()->uses_swatch);
    }

    public function test_swatches_can_be_turned_on_at_any_time(): void
    {
        $group = OptionGroup::factory()->create();
        $this->item($group);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => $group->name, 'uses_swatch' => true]))->assertSessionHasNoErrors();

        $this->assertTrue($group->fresh()->uses_swatch);
    }

    public function test_a_group_is_updated_and_the_change_is_logged(): void
    {
        $group = OptionGroup::factory()->create(['name' => 'Stari', 'sort_order' => 3]);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => 'Novi', 'sort_order' => '9']))
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'option_group.updated')->where('subject_id', $group->id)->sole();
        $this->assertEquals(['old' => 'Stari', 'new' => 'Novi'], $log->changes['name']);
        $this->assertEquals(['old' => 3, 'new' => 9], $log->changes['sort_order']);

        // An empty order keeps the current one.
        $this->as()->patch("/admin/catalog/option-groups/{$group->id}", $this->payload(['name' => 'Novi', 'sort_order' => '']));
        $this->assertSame(9, $group->fresh()->sort_order);
    }

    // --- deactivating ---

    public function test_deactivating_a_group_changes_neither_its_items_nor_version_availability(): void
    {
        $version = Version::factory()->create();
        $group = OptionGroup::factory()->create();
        $item = $this->item($group);
        $this->line($item, 'standard', null, $version->trim);

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}/active", ['is_active' => false])
            ->assertSessionHas('success', 'Status je promenjen.');

        $this->assertFalse($group->fresh()->is_active);
        $this->assertTrue($item->fresh()->is_active);
        $this->assertSame(1, Version::available()->count());
        // Only the offer of its items changes: they leave EquipmentItem::offerable().
        $this->assertSame(0, EquipmentItem::offerable()->count());

        $this->as()->patch("/admin/catalog/option-groups/{$group->id}/active", ['is_active' => true]);
        $this->assertSame(1, EquipmentItem::offerable()->count());
    }

    // --- deleting ---

    public function test_a_group_with_items_cannot_be_deleted_and_an_empty_one_can(): void
    {
        $used = OptionGroup::factory()->create();
        $empty = OptionGroup::factory()->create();
        $this->item($used);
        $this->item($used);

        $this->as()->delete("/admin/catalog/option-groups/{$used->id}")
            ->assertSessionHas('error', 'Grupa opcija ima zavisne redove (stavki: 2). Deaktivirajte je umesto brisanja.');
        $this->assertNotNull($used->fresh());

        $this->as()->delete("/admin/catalog/option-groups/{$empty->id}")->assertSessionHas('success', 'Obrisano.');
        $this->assertNull(OptionGroup::find($empty->id));
        $this->assertSame(1, ActivityLog::where('action', 'option_group.deleted')->where('subject_id', $empty->id)->count());
    }

    // --- translations ---

    public function test_the_new_labels_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['option.selection.single', 'option.selection.multiple', 'catalog.attr.selection', 'catalog.attr.uses_swatch', 'Option groups', 'Selection', 'Swatches'] as $key) {
            $this->assertNotEmpty($translations[$key] ?? null, $key);
        }
    }
}
