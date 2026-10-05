<?php

namespace Tests\Feature;

use App\Enums\EquipmentCategory;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\OfferItemResolver;
use App\Services\OfferItemsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferItemResolverTest extends TestCase
{
    use RefreshDatabase;

    private OfferItemResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OfferItemResolver;
    }

    /** @param bool $named fixed names (only for a test that asserts them; names are unique) */
    private function version(int $price = 2_500_000, bool $named = false): Version
    {
        $model = CarModel::factory()->create($named ? ['name' => 'Octavia'] : []);
        $trim = Trim::factory()->create(['car_model_id' => $model->id] + ($named ? ['name' => 'Style'] : []));

        return Version::factory()->create(['trim_id' => $trim->id,            'engine_id' => Engine::factory()->create($named ? ['name' => '2.0 TDI', 'power_kw' => 110] : []),            'transmission_id' => Transmission::factory()->create($named ? ['name' => 'DSG 7'] : []),            'base_price_cents' => $price]);
    }

    private function extra(Version $version, int $price, ?OptionGroup $group = null, array $attributes = []): EquipmentItem
    {
        $item = EquipmentItem::factory()->create(array_merge(
            $group ? ['group_id' => $group->id, 'category' => $group->category] : ['category' => EquipmentCategory::Comfort],
            $attributes,
        ));

        TrimEquipment::factory()->optional($price)->create(['trim_id' => $version->trim_id, 'equipment_item_id' => $item->id]);

        return $item;
    }

    private function standard(Version $version, EquipmentItem $item): void
    {
        TrimEquipment::factory()->create(['trim_id' => $version->trim_id, 'equipment_item_id' => $item->id]);
    }

    /** @return array{version_id: int, quantity: int, option_ids: list<int>} */
    private function choice(Version $version, array $optionIds = [], int $quantity = 1): array
    {
        return ['version_id' => $version->id, 'quantity' => $quantity, 'option_ids' => $optionIds];
    }

    /** Asserts that exactly this key is reported, with a message. */
    private function assertRejected(mixed $items, string $key): void
    {
        try {
            $this->resolver->resolve($items);
            $this->fail("Expected a rejection with the key '$key'.");
        } catch (OfferItemsException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            $this->assertNotSame('', $e->errors()[$key][0]);
        }
    }

    public function test_a_valid_choice_gives_the_snapshot_with_prices_from_the_catalog(): void
    {
        $version = $this->version(2_500_000, true);
        $first = $this->extra($version, 150_000, null, ['name' => 'Climatronic', 'category' => EquipmentCategory::Comfort]);
        $second = $this->extra($version, 99_900, null, ['name' => 'Alarm', 'category' => EquipmentCategory::Safety]);

        $resolved = $this->resolver->resolve([$this->choice($version, [$second->id, $first->id], 2)]);

        $this->assertCount(1, $resolved);
        $this->assertSame([
            'position' => 1,
            'quantity' => 2,
            'car_model_name' => 'Octavia',
            'trim_name' => 'Style',
            'engine_name' => '2.0 TDI',
            'fuel_type' => $version->engine->fuel_type->value,
            'power_kw' => 110,
            'transmission_name' => 'DSG 7',
            'drive' => $version->transmission->drive->value,
            'version_price_cents' => 2_500_000,
        ], $resolved[0]['item']);

        // Catalog order (category order, then sort order), not the order the client sent.
        $order = collect(EquipmentCategory::cases())->map->value->flip();
        $expected = collect([$first, $second])->sortBy(fn ($i) => $order[$i->category->value])->values();

        $this->assertSame($expected->pluck('name')->all(), array_column($resolved[0]['options'], 'name'));
        $this->assertSame([1, 2], array_column($resolved[0]['options'], 'position'));
        $this->assertSame([false, false], array_column($resolved[0]['options'], 'is_surcharge'));
        $this->assertEqualsCanonicalizing([150_000, 99_900], array_column($resolved[0]['options'], 'price_cents'));
    }

    public function test_positions_follow_the_order_of_the_input_starting_at_one(): void
    {
        $a = $this->version();
        $b = $this->version();

        $resolved = $this->resolver->resolve([$this->choice($b), $this->choice($a)]);

        $this->assertSame([1, 2], array_column(array_column($resolved, 'item'), 'position'));
        $this->assertSame($b->base_price_cents, $resolved[0]['item']['version_price_cents']);
    }

    public function test_a_single_group_extra_is_a_surcharge_and_the_standard_item_is_not_listed(): void
    {
        $version = $this->version();
        $group = OptionGroup::factory()->create(['name' => 'Boja karoserije', 'category' => EquipmentCategory::Exterior]);
        $this->standard($version, EquipmentItem::factory()->create(['group_id' => $group->id, 'category' => $group->category, 'name' => 'Bela']));
        $metallic = $this->extra($version, 120_000, $group, ['name' => 'Metalik']);

        $options = $this->resolver->resolve([$this->choice($version, [$metallic->id])])[0]['options'];

        $this->assertCount(1, $options);
        $this->assertSame('Metalik', $options[0]['name']);
        $this->assertTrue($options[0]['is_surcharge']);
        $this->assertSame('Boja karoserije', $options[0]['group_name']);
        $this->assertSame(120_000, $options[0]['price_cents']);
        $this->assertSame('exterior', $options[0]['category']);
    }

    public function test_nothing_chosen_from_a_single_group_keeps_the_standard_with_no_row(): void
    {
        $version = $this->version();
        $group = OptionGroup::factory()->create();
        $this->standard($version, EquipmentItem::factory()->create(['group_id' => $group->id, 'category' => $group->category]));
        $this->extra($version, 120_000, $group);

        $this->assertSame([], $this->resolver->resolve([$this->choice($version)])[0]['options']);
    }

    public function test_a_multiple_group_extra_is_not_a_surcharge_and_several_can_be_chosen(): void
    {
        $version = $this->version();
        $group = OptionGroup::factory()->multiple()->create(['name' => 'Paketi']);
        $a = $this->extra($version, 10_000, $group);
        $b = $this->extra($version, 20_000, $group);

        $options = $this->resolver->resolve([$this->choice($version, [$a->id, $b->id])])[0]['options'];

        $this->assertSame([false, false], array_column($options, 'is_surcharge'));
        $this->assertSame(['Paketi', 'Paketi'], array_column($options, 'group_name'));
    }

    public function test_an_unavailable_version_is_rejected(): void
    {
        $inactive = Version::factory()->inactive()->create();
        $hiddenModel = $this->version();
        $hiddenModel->trim->carModel->update(['is_active' => false]);

        $this->assertRejected([$this->choice($inactive)], 'items.0.version_id');
        $this->assertRejected([$this->choice($hiddenModel)], 'items.0.version_id');
        $this->assertRejected([['version_id' => 999_999, 'quantity' => 1, 'option_ids' => []]], 'items.0.version_id');
    }

    public function test_options_that_cannot_be_chosen_are_rejected_with_the_key_of_the_option(): void
    {
        $version = $this->version();
        $other = $this->version();

        $standard = EquipmentItem::factory()->create();
        $this->standard($version, $standard);

        $group = OptionGroup::factory()->create();
        $groupStandard = EquipmentItem::factory()->create(['group_id' => $group->id, 'category' => $group->category]);
        $this->standard($version, $groupStandard);

        $noRow = EquipmentItem::factory()->create();
        $inactiveItem = $this->extra($version, 1000, null, ['is_active' => false]);
        $inactiveGroup = OptionGroup::factory()->inactive()->create();
        $inGroup = $this->extra($version, 1000, $inactiveGroup);
        $otherLine = $this->extra($other, 1000);

        foreach ([$standard, $groupStandard, $noRow, $inactiveItem, $inGroup, $otherLine] as $item) {
            $this->assertRejected([$this->choice($version, [$item->id])], 'items.0.option_ids.0');
        }

        $this->assertRejected([$this->choice($version, [999_999])], 'items.0.option_ids.0');
    }

    public function test_the_standard_item_gets_its_own_message(): void
    {
        $version = $this->version();
        $standard = EquipmentItem::factory()->create();
        $this->standard($version, $standard);

        try {
            $this->resolver->resolve([$this->choice($version, [$standard->id])]);
            $this->fail('A standard item cannot be chosen.');
        } catch (OfferItemsException $e) {
            $this->assertSame(__('offer.item.option_standard'), $e->errors()['items.0.option_ids.0'][0]);
        }
    }

    public function test_a_duplicate_and_two_items_of_one_single_group_are_rejected(): void
    {
        $version = $this->version();
        $extra = $this->extra($version, 1000);
        $group = OptionGroup::factory()->create();
        $a = $this->extra($version, 1000, $group);
        $b = $this->extra($version, 2000, $group);

        $this->assertRejected([$this->choice($version, [$extra->id, $extra->id])], 'items.0.option_ids.1');
        $this->assertRejected([$this->choice($version, [$a->id, $b->id])], 'items.0.option_ids.1');
    }

    public function test_the_errors_of_every_item_are_reported_with_its_own_key(): void
    {
        $version = $this->version();

        try {
            $this->resolver->resolve([$this->choice($version), $this->choice($version, [999_999]), $this->choice(Version::factory()->inactive()->create())]);
            $this->fail('Two items are invalid.');
        } catch (OfferItemsException $e) {
            $this->assertEqualsCanonicalizing(['items.1.option_ids.0', 'items.2.version_id'], array_keys($e->errors()));
        }
    }

    public function test_client_prices_and_names_are_never_taken(): void
    {
        $version = $this->version(2_500_000, true);
        $extra = $this->extra($version, 150_000, null, ['name' => 'Climatronic']);

        $item = $this->choice($version, [$extra->id]) + [
            'version_price_cents' => 1,
            'price_cents' => 1,
            'car_model_name' => 'Hack',
            'options' => [['name' => 'Free', 'price_cents' => 0]],
        ];
        $resolved = $this->resolver->resolve([$item]);

        $this->assertSame(2_500_000, $resolved[0]['item']['version_price_cents']);
        $this->assertSame('Octavia', $resolved[0]['item']['car_model_name']);
        $this->assertSame([150_000], array_column($resolved[0]['options'], 'price_cents'));
        $this->assertSame(['Climatronic'], array_column($resolved[0]['options'], 'name'));

        // Options as objects (with a client price) are not ids at all.
        $this->assertRejected([['version_id' => $version->id, 'quantity' => 1, 'option_ids' => [['id' => $extra->id, 'price_cents' => 1]]]], 'items.0.option_ids');
    }

    public function test_wrong_shapes_and_types_are_a_validation_error_never_a_type_error(): void
    {
        $version = $this->version();
        $id = $version->id;

        $this->assertRejected('x', 'items');
        $this->assertRejected(['a' => $this->choice($version)], 'items');
        $this->assertRejected([5], 'items.0');
        $this->assertRejected([['quantity' => 1, 'option_ids' => []]], 'items.0.version_id');
        $this->assertRejected([['version_id' => '5', 'quantity' => 1, 'option_ids' => []]], 'items.0.version_id');
        $this->assertRejected([['version_id' => 1.5, 'quantity' => 1, 'option_ids' => []]], 'items.0.version_id');
        $this->assertRejected([['version_id' => 0, 'quantity' => 1, 'option_ids' => []]], 'items.0.version_id');
        $this->assertRejected([['version_id' => $id, 'option_ids' => []]], 'items.0.quantity');
        $this->assertRejected([['version_id' => $id, 'quantity' => 0, 'option_ids' => []]], 'items.0.quantity');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1000, 'option_ids' => []]], 'items.0.quantity');
        $this->assertRejected([['version_id' => $id, 'quantity' => '2', 'option_ids' => []]], 'items.0.quantity');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1.5, 'option_ids' => []]], 'items.0.quantity');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => 5]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => ['a' => 1]]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => ['5']]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => [1.5]]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => [null]]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => [true]]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => [0]]], 'items.0.option_ids');
        $this->assertRejected([['version_id' => $id, 'quantity' => 1, 'option_ids' => range(1, 201)]], 'items.0.option_ids');
    }

    public function test_the_limits_on_items_and_on_options_of_the_whole_offer(): void
    {
        $version = $this->version();

        $this->assertRejected(array_fill(0, 21, $this->choice($version)), 'items');
        $this->assertRejected(array_fill(0, 3, $this->choice($version, range(1, 200))), 'items');
        $this->assertSame([], $this->resolver->resolve([]));
    }

    public function test_the_extras_query_has_only_optional_offerable_rows_in_order_with_the_price(): void
    {
        $version = $this->version();
        $this->extra($version, 5000, null, ['name' => 'B', 'category' => EquipmentCategory::Safety]);
        $this->extra($version, 7000, null, ['name' => 'A', 'category' => EquipmentCategory::Exterior]);
        $this->standard($version, EquipmentItem::factory()->create(['name' => 'Standard']));
        $this->extra($version, 1000, null, ['name' => 'Off', 'is_active' => false]);
        $this->extra($this->version(), 9000, null, ['name' => 'Other line']);

        $extras = $version->offerableExtras()->get();

        $this->assertSame(['B', 'A'], $extras->pluck('name')->all());
        $this->assertSame([5000, 7000], $extras->map(fn ($e) => (int) $e->extra_price_cents)->all());
    }
}
