<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesImages;
use Tests\TestCase;

class CatalogEquipmentCrudTest extends TestCase
{
    use MakesImages, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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
        return array_merge(['name' => 'Parking senzori', 'category' => 'comfort', 'is_active' => 1], $overrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $query = ''): array
    {
        return $this->as()->get('/admin/catalog/equipment'.$query)->viewData('page')['props']['items']['data'];
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

    private function grouped(OptionGroup $group, array $attributes = []): EquipmentItem
    {
        return EquipmentItem::factory()->create($attributes + ['group_id' => $group->id, 'category' => $group->category]);
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $item = EquipmentItem::factory()->create();
        $requests = [
            ['get', '/admin/catalog/equipment'], ['post', '/admin/catalog/equipment'],
            ['patch', "/admin/catalog/equipment/{$item->id}"], ['patch', "/admin/catalog/equipment/{$item->id}/active"],
            ['delete', "/admin/catalog/equipment/{$item->id}"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        $this->assertNotNull($item->fresh());
    }

    // --- list ---

    public function test_the_list_shows_group_swatch_lines_and_image(): void
    {
        $colors = OptionGroup::factory()->swatch()->create(['name' => 'Boje']);
        $item = $this->grouped($colors, ['name' => 'Crvena', 'swatch_hex' => '#C62828']);
        $this->line($item, 'standard');
        $this->line($item, 'optional', 30_000);
        EquipmentItem::factory()->create(['name' => 'Bez grupe']);

        $row = collect($this->rows())->firstWhere('name', 'Crvena');

        $this->assertSame('Boje', $row['group_name']);
        $this->assertSame($colors->id, $row['group_id']);
        $this->assertSame('#C62828', $row['swatch_hex']);
        $this->assertSame(2, $row['lines_count']);
        $this->assertSame(1, $row['standard_single_lines']);
        $this->assertNull($row['image_url']);
        $this->assertFalse($row['group_inactive']);

        $plain = collect($this->rows())->firstWhere('name', 'Bez grupe');
        $this->assertNull($plain['group_name']);
        $this->assertSame(0, $plain['lines_count']);
        $this->assertSame(0, $plain['standard_single_lines']);

        $this->as()->get('/admin/catalog/equipment')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Equipment')->has('groups', 1)->has('categories', 6));
    }

    public function test_an_active_item_of_an_inactive_group_is_flagged(): void
    {
        $group = OptionGroup::factory()->inactive()->create();
        $this->grouped($group, ['name' => 'U neaktivnoj grupi']);

        $this->assertTrue($this->rows()[0]['group_inactive']);
    }

    public function test_the_filters_and_the_search(): void
    {
        $wheels = OptionGroup::factory()->create(['category' => 'exterior']);
        $a = $this->grouped($wheels, ['name' => 'Felne 17']);
        $b = EquipmentItem::factory()->create(['name' => 'Klima uredjaj', 'category' => 'comfort']);
        $c = EquipmentItem::factory()->inactive()->create(['name' => 'Kuka za vucu', 'category' => 'exterior']);

        $names = fn (string $query) => collect($this->rows($query))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Felne 17', 'Klima uredjaj', 'Kuka za vucu'], $names(''));
        $this->assertSame(['Klima uredjaj'], $names('?category=comfort'));
        $this->assertSame(['Felne 17', 'Kuka za vucu'], $names('?category=exterior'));
        $this->assertSame(['Felne 17'], $names('?group='.$wheels->id));
        $this->assertSame(['Klima uredjaj', 'Kuka za vucu'], $names('?group=none'));
        $this->assertSame(['Kuka za vucu'], $names('?status=inactive'));
        $this->assertSame(['Felne 17', 'Klima uredjaj'], $names('?status=active'));
        $this->assertSame(['Klima uredjaj'], $names('?q=klima'));
        $this->assertSame([], $names('?category=nonsense&q=zzz'));
        $this->assertContains($a->id, array_column($this->rows('?category=nonsense'), 'id'));
    }

    public function test_like_wildcards_in_the_search_are_escaped(): void
    {
        EquipmentItem::factory()->create(['name' => 'Popust 50%']);
        EquipmentItem::factory()->create(['name' => 'Oprema_A']);
        EquipmentItem::factory()->create(['name' => 'Obicna']);

        $names = fn (string $q) => collect($this->rows('?'.http_build_query(['q' => $q])))->pluck('name')->all();

        $this->assertSame(['Popust 50%'], $names('%'));
        $this->assertSame(['Oprema_A'], $names('_'));
        $this->assertSame([], $names('\\'));
    }

    public function test_the_list_is_ordered_by_category_then_order_and_paginated(): void
    {
        EquipmentItem::factory()->create(['name' => 'Voznja', 'category' => 'driving', 'sort_order' => 1]);
        EquipmentItem::factory()->create(['name' => 'Komfor 2', 'category' => 'comfort', 'sort_order' => 2]);
        EquipmentItem::factory()->create(['name' => 'Komfor 1', 'category' => 'comfort', 'sort_order' => 1]);
        EquipmentItem::factory()->create(['name' => 'Sigurnost', 'category' => 'safety', 'sort_order' => 9]);

        $this->assertSame(['Sigurnost', 'Komfor 1', 'Komfor 2', 'Voznja'], array_column($this->rows(), 'name'));

        EquipmentItem::factory()->count(26)->create();
        $this->as()->get('/admin/catalog/equipment')->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 25)->where('items.last_page', 2)->where('items.total', 30));
    }

    public function test_the_list_runs_a_constant_number_of_queries(): void
    {
        $group = OptionGroup::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->line($this->grouped($group), 'optional', 100);
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as()->get('/admin/catalog/equipment')->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $count();
        foreach (range(1, 15) as $i) {
            $this->line($this->grouped($group), 'optional', 100);
        }

        // "In N trims" comes from withCount, not from a query per row.
        $this->assertSame($few, $count());
    }

    // --- creating and updating ---

    public function test_an_independent_item_is_created_with_the_next_order_in_its_category(): void
    {
        EquipmentItem::factory()->create(['category' => 'comfort', 'sort_order' => 7]);

        $this->as()->post('/admin/catalog/equipment', $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Dodato.');

        $item = EquipmentItem::where('name', 'Parking senzori')->sole();
        $this->assertSame(8, $item->sort_order);
        $this->assertTrue($item->is_active);
        $this->assertNull($item->group_id);
        $this->assertNull($item->swatch_hex);
        $this->assertSame(1, ActivityLog::where('action', 'equipment_item.created')->where('subject_id', $item->id)->count());
    }

    public function test_the_name_is_unique_without_regard_to_case(): void
    {
        EquipmentItem::factory()->create(['name' => 'Parking senzori']);

        foreach (['Parking senzori', 'parking senzori', 'PARKING SENZORI', ' parking senzori '] as $name) {
            $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => $name]))
                ->assertSessionHasErrors(['name' => 'Stavka opreme sa tim nazivom već postoji.']);
        }
        $this->assertSame(1, EquipmentItem::count());

        $item = EquipmentItem::sole();
        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'PARKING SENZORI']))->assertSessionHasNoErrors();
        $this->assertSame('PARKING SENZORI', $item->fresh()->name);
    }

    public function test_input_is_validated(): void
    {
        foreach ([
            ['name' => ''], ['name' => str_repeat('a', 151)], ['name' => "Bad\x00name"],
            ['category' => 'nonsense'], ['category' => ''],
            ['sort_order' => '-1'], ['sort_order' => '65536'], ['sort_order' => 'abc'],
            ['group_id' => 9999], ['swatch_hex' => '#FFF'],
        ] as $bad) {
            $this->as()->post('/admin/catalog/equipment', $this->payload($bad))->assertSessionHasErrors();
        }

        $this->assertSame(0, EquipmentItem::count());
    }

    public function test_a_group_fixes_the_category(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);

        $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'Felne', 'group_id' => $group->id, 'category' => 'safety']))
            ->assertSessionHasErrors(['category' => 'Kategorija mora biti kategorija grupe (Spoljašnjost).']);

        $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'Felne', 'group_id' => $group->id, 'category' => 'exterior']))
            ->assertSessionHasNoErrors();

        $this->assertSame($group->id, EquipmentItem::where('name', 'Felne')->sole()->group_id);
    }

    public function test_a_swatch_needs_a_group_that_uses_swatches_and_is_stored_in_capitals(): void
    {
        $colors = OptionGroup::factory()->swatch()->create();
        $wheels = OptionGroup::factory()->create();
        $expected = 'Uzorak boje je dozvoljen samo za stavke grupe sa uzorcima boja.';

        $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'A', 'swatch_hex' => '#C62828']))
            ->assertSessionHasErrors(['swatch_hex' => $expected]);
        $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'B', 'group_id' => $wheels->id, 'category' => 'exterior', 'swatch_hex' => '#C62828']))
            ->assertSessionHasErrors(['swatch_hex' => $expected]);

        $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'C', 'group_id' => $colors->id, 'category' => 'exterior', 'swatch_hex' => '#c62828']))
            ->assertSessionHasNoErrors();
        $this->assertSame('#C62828', EquipmentItem::where('name', 'C')->sole()->swatch_hex);

        foreach (['#FFF', 'FFFFFF', '#GGGGGG', '#FFFFFFF'] as $bad) {
            $this->as()->post('/admin/catalog/equipment', $this->payload(['name' => 'D', 'group_id' => $colors->id, 'category' => 'exterior', 'swatch_hex' => $bad]))
                ->assertSessionHasErrors('swatch_hex');
        }
    }

    public function test_an_item_is_updated_and_the_change_is_logged(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Stari naziv', 'category' => 'comfort', 'sort_order' => 3]);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Novi naziv', 'category' => 'safety', 'sort_order' => '9']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Sačuvano.');

        $fresh = $item->fresh();
        $this->assertSame('Novi naziv', $fresh->name);
        $this->assertSame('safety', $fresh->category->value);
        $this->assertSame(9, $fresh->sort_order);

        $log = ActivityLog::where('action', 'equipment_item.updated')->where('subject_id', $item->id)->sole();
        $this->assertEquals(['old' => 'Stari naziv', 'new' => 'Novi naziv'], $log->changes['name']);
        $this->assertEquals(['old' => 3, 'new' => 9], $log->changes['sort_order']);
    }

    // --- the group of an item that is already on a trim ---

    public function test_the_group_of_an_item_that_is_on_a_trim_cannot_change(): void
    {
        $from = OptionGroup::factory()->create(['category' => 'exterior']);
        $to = OptionGroup::factory()->create(['category' => 'exterior']);
        $item = $this->grouped($from, ['name' => 'Felne']);
        $this->line($item, 'optional', 100);
        $this->line($item, 'optional', 100);
        $message = 'Stavka je u linijama (2); grupa se ne može menjati. Deaktivirajte je ili je menjajte u matrici opreme.';

        // To another group, out of the group, and an ungrouped item into a group.
        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Felne', 'category' => 'exterior', 'group_id' => $to->id]))
            ->assertSessionHasErrors(['group_id' => $message]);
        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Felne', 'category' => 'exterior', 'group_id' => '']))
            ->assertSessionHasErrors(['group_id' => $message]);

        $plain = EquipmentItem::factory()->create(['name' => 'Bez grupe', 'category' => 'exterior']);
        $this->line($plain, 'optional', 100);
        $this->as()->post("/admin/catalog/equipment/{$plain->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Bez grupe', 'category' => 'exterior', 'group_id' => $from->id]))
            ->assertSessionHasErrors('group_id');

        $this->assertSame($from->id, $item->fresh()->group_id);
        $this->assertNull($plain->fresh()->group_id);
    }

    public function test_saving_the_same_group_of_an_item_on_a_trim_is_fine(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);
        $item = $this->grouped($group, ['name' => 'Felne']);
        $this->line($item, 'optional', 100);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Felne 18', 'category' => 'exterior', 'group_id' => $group->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Felne 18', $item->fresh()->name);
    }

    public function test_an_item_without_lines_can_change_its_group_and_the_category_follows(): void
    {
        $exterior = OptionGroup::factory()->create(['category' => 'exterior']);
        $interior = OptionGroup::factory()->create(['category' => 'interior']);
        $item = $this->grouped($exterior, ['name' => 'Sedista']);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Sedista', 'category' => 'interior', 'group_id' => $interior->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($interior->id, $item->fresh()->group_id);
        $this->assertSame('interior', $item->fresh()->category->value);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Sedista', 'category' => 'interior', 'group_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->group_id);
    }

    // --- deactivating the standard item of a single group ---

    public function test_the_standard_item_of_a_single_group_cannot_be_deactivated(): void
    {
        $group = OptionGroup::factory()->create();
        $standard = $this->grouped($group, ['name' => 'Bela']);
        $this->line($standard, 'standard');
        $this->line($standard, 'standard');
        $message = 'Stavka je standardna u grupi „jedno od više“ na linijama (2); zamenite je u matrici opreme pre deaktivacije.';

        $this->as()->patch("/admin/catalog/equipment/{$standard->id}/active", ['is_active' => false])
            ->assertSessionHas('error', $message);
        $this->assertTrue($standard->fresh()->is_active);

        $this->as()->post("/admin/catalog/equipment/{$standard->id}", $this->payload(['_method' => 'PATCH', 'name' => 'Bela', 'category' => 'exterior', 'group_id' => $group->id, 'is_active' => 0]))
            ->assertSessionHasErrors(['is_active' => $message]);
        $this->assertTrue($standard->fresh()->is_active);

        // A lines count in the list lets the screen say the same thing before the request.
        $this->assertSame(2, collect($this->rows())->firstWhere('name', 'Bela')['standard_single_lines']);
    }

    public function test_other_items_can_still_be_deactivated_and_reactivated(): void
    {
        $single = OptionGroup::factory()->create();
        $multiple = OptionGroup::factory()->multiple()->create();

        $surcharge = $this->grouped($single, ['name' => 'Crvena']);
        $this->line($surcharge, 'optional', 30_000);
        $inMultiple = $this->grouped($multiple, ['name' => 'Cerade']);
        $this->line($inMultiple, 'standard');
        $independent = EquipmentItem::factory()->create(['name' => 'Kuka']);
        $this->line($independent, 'standard');
        $unused = $this->grouped($single, ['name' => 'Nova']);

        foreach ([$surcharge, $inMultiple, $independent, $unused] as $item) {
            $this->as()->patch("/admin/catalog/equipment/{$item->id}/active", ['is_active' => false])->assertSessionHas('success', 'Status je promenjen.');
            $this->assertFalse($item->fresh()->is_active, $item->name);

            $this->as()->patch("/admin/catalog/equipment/{$item->id}/active", ['is_active' => true]);
            $this->assertTrue($item->fresh()->is_active);
        }
    }

    public function test_an_already_inactive_standard_item_can_be_reactivated_and_its_active_flag_is_validated(): void
    {
        $group = OptionGroup::factory()->create();
        $item = $this->grouped($group, ['is_active' => false]);
        $this->line($item, 'standard');

        $this->as()->patch("/admin/catalog/equipment/{$item->id}/active", ['is_active' => true])->assertSessionHas('success');
        $this->as()->patch("/admin/catalog/equipment/{$item->id}/active", [])->assertSessionHasErrors('is_active');
        $this->assertTrue($item->fresh()->is_active);
    }

    // --- deleting ---

    public function test_an_item_on_a_trim_cannot_be_deleted_and_the_message_has_the_number(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->line($item, 'standard');
        $this->line($item, 'optional', 100);

        $this->as()->delete("/admin/catalog/equipment/{$item->id}")
            ->assertSessionHas('error', 'Stavka opreme ima zavisne redove (linija: 2). Deaktivirajte je umesto brisanja.');

        $this->assertNotNull($item->fresh());
    }

    public function test_an_unused_item_is_deleted_with_its_image_and_the_delete_is_logged(): void
    {
        $this->as()->post('/admin/catalog/equipment', $this->payload(['image' => $this->upload('a.png', $this->pngBytes())]))->assertSessionHasNoErrors();
        $item = EquipmentItem::sole();
        $path = $item->image_path;
        Storage::disk('public')->assertExists($path);

        $this->as()->delete("/admin/catalog/equipment/{$item->id}")->assertSessionHas('success', 'Obrisano.');

        $this->assertNull(EquipmentItem::find($item->id));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(1, ActivityLog::where('action', 'equipment_item.deleted')->where('subject_id', $item->id)->count());
    }

    // --- images ---

    public function test_an_image_is_stored_replaced_and_removed(): void
    {
        $this->as()->post('/admin/catalog/equipment', $this->payload(['image' => $this->upload('photo.jpg', $this->pngBytes())]))->assertSessionHasNoErrors();
        $item = EquipmentItem::sole();
        $first = $item->image_path;
        $this->assertMatchesRegularExpression('#^catalog/equipment/[A-Za-z0-9]{40}\.png$#', $first);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'image' => $this->upload('b.jpg', $this->jpegBytes())]))->assertSessionHasNoErrors();
        $second = $item->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'remove_image' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles('catalog/equipment'));
    }

    public function test_bad_images_are_rejected_and_the_old_image_stays(): void
    {
        $this->as()->post('/admin/catalog/equipment', $this->payload(['image' => $this->upload('a.png', $this->pngBytes())]));
        $item = EquipmentItem::sole();
        $path = $item->image_path;

        foreach ([
            $this->upload('logo.svg', $this->svgBytes()),
            $this->upload('evil.jpg', '<?php echo 1; ?>'),
            $this->upload('big.png', $this->pngBytes().str_repeat("\0", 3 * 1024 * 1024)),
            $this->upload('small.png', $this->pngBytes(100, 100)),
        ] as $bad) {
            $this->as()->post("/admin/catalog/equipment/{$item->id}", $this->payload(['_method' => 'PATCH', 'image' => $bad]))->assertSessionHasErrors('image');
        }

        $this->assertSame($path, $item->fresh()->image_path);
        $this->assertSame([$path], Storage::disk('public')->allFiles('catalog/equipment'));
    }

    public function test_a_body_above_post_max_size_becomes_a_message_next_to_the_image_field(): void
    {
        $item = EquipmentItem::factory()->create();

        foreach (['/admin/catalog/equipment', "/admin/catalog/equipment/{$item->id}"] as $url) {
            $this->as()->call('POST', $url, ['_method' => $url === '/admin/catalog/equipment' ? 'POST' : 'PATCH'], [], [], [
                'CONTENT_LENGTH' => 999_999_999,
                'HTTP_REFERER' => url('/admin/catalog/equipment'),
            ])->assertRedirect('/admin/catalog/equipment')
                ->assertSessionHasErrors(['image' => 'Slika je veća od dozvoljenih 2 MB.']);
        }

        $this->assertSame(1, EquipmentItem::count());
    }

    // --- offerable equipment ---

    public function test_only_active_items_of_active_groups_or_without_a_group_can_be_offered(): void
    {
        $active = OptionGroup::factory()->create();
        $inactive = OptionGroup::factory()->inactive()->create();
        $independent = EquipmentItem::factory()->create(['name' => 'Samostalna']);
        $inActive = $this->grouped($active, ['name' => 'U aktivnoj']);
        $inInactive = $this->grouped($inactive, ['name' => 'U neaktivnoj']);
        $off = EquipmentItem::factory()->inactive()->create(['name' => 'Isključena']);

        $this->assertEqualsCanonicalizing(
            ['Samostalna', 'U aktivnoj'],
            EquipmentItem::offerable()->pluck('name')->all(),
        );

        // Trim::activeEquipment() uses the same single place.
        $trim = Trim::factory()->create();
        foreach ([$independent, $inActive, $inInactive, $off] as $item) {
            $this->line($item, 'optional', 100, $trim);
        }
        $this->assertEqualsCanonicalizing(['Samostalna', 'U aktivnoj'], $trim->activeEquipment()->pluck('equipment_items.name')->all());

        // Reactivating the group brings its items back.
        $inactive->update(['is_active' => true]);
        $this->assertContains('U neaktivnoj', $trim->activeEquipment()->pluck('equipment_items.name')->all());
    }

    public function test_the_new_labels_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['catalog.entity.equipment_item', 'catalog.entity.version', 'catalog.entity.option_group', 'dependency.lines', 'dependency.items', 'catalog.attr.group_id', 'catalog.attr.swatch_hex', 'Option groups', 'Equipment item'] as $key) {
            $this->assertNotEmpty($translations[$key] ?? null, $key);
        }
    }
}
