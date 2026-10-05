<?php

namespace Tests\Feature;

use App\Enums\EquipmentAvailability;
use App\Enums\EquipmentCategory;
use App\Enums\OptionSelection;
use App\Models\ActivityLog;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Policies\AdminOnlyPolicy;
use App\Support\OptionGroupRule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class OptionGroupTest extends TestCase
{
    use RefreshDatabase;

    private function item(OptionGroup $group, array $attributes = []): EquipmentItem
    {
        return EquipmentItem::factory()->create($attributes + ['group_id' => $group->id, 'category' => $group->category]);
    }

    private function row(Trim $trim, EquipmentItem $item, string $availability, ?int $price = null): TrimEquipment
    {
        return TrimEquipment::create([
            'trim_id' => $trim->id,
            'equipment_item_id' => $item->id,
            'availability' => $availability,
            'price_cents' => $price,
        ]);
    }

    // --- schema and relations ---

    public function test_the_schema_has_the_new_columns_with_the_right_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('option_groups', ['name', 'slug', 'category', 'selection', 'uses_swatch', 'sort_order', 'is_active']));
        $this->assertTrue(Schema::hasColumns('equipment_items', ['group_id', 'image_path', 'swatch_hex']));

        $group = OptionGroup::create(['name' => 'Boje', 'slug' => 'boje', 'category' => 'exterior'])->fresh();

        $this->assertSame(OptionSelection::Single, $group->selection);
        $this->assertFalse($group->uses_swatch);
        $this->assertTrue($group->is_active);
        $this->assertSame(EquipmentCategory::Exterior, $group->category);

        // An item without a group is an independent extra, as before.
        $item = EquipmentItem::factory()->create();
        $this->assertNull($item->group_id);
        $this->assertNull($item->image_path);
        $this->assertNull($item->swatch_hex);
        $this->assertNull($item->group);
    }

    public function test_relations_work_in_both_directions(): void
    {
        $group = OptionGroup::factory()->create();
        $item = $this->item($group);

        $this->assertTrue($item->group->is($group));
        $this->assertTrue($group->items->first()->is($item));
        $this->assertSame(1, $group->items()->count());
    }

    public function test_the_slug_is_unique(): void
    {
        OptionGroup::factory()->create(['name' => 'Boje', 'slug' => 'boje']);

        $this->expectException(QueryException::class);

        OptionGroup::factory()->create(['name' => 'Druge boje', 'slug' => 'boje']);
    }

    public function test_a_group_with_items_cannot_be_deleted(): void
    {
        $group = OptionGroup::factory()->create();
        $empty = OptionGroup::factory()->create();
        $this->item($group);

        try {
            $group->delete();
            $this->fail('A group with items must not be deletable (foreign key RESTRICT).');
        } catch (QueryException) {
            $this->assertNotNull($group->fresh());
        }

        $empty->delete();
        $this->assertNull(OptionGroup::find($empty->id));
    }

    public function test_changes_of_a_group_are_logged_and_the_policy_is_admin_only(): void
    {
        $group = OptionGroup::factory()->create(['name' => 'Boje', 'slug' => 'boje']);
        $group->update(['selection' => 'multiple']);

        $log = ActivityLog::where('action', 'option_group.updated')->where('subject_id', $group->id)->sole();
        $this->assertEquals(['old' => 'single', 'new' => 'multiple'], $log->changes['selection']);

        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();
        $this->assertInstanceOf(AdminOnlyPolicy::class, Gate::getPolicyFor($group));
        foreach (['viewAny' => OptionGroup::class, 'create' => OptionGroup::class, 'view' => $group, 'update' => $group, 'delete' => $group] as $ability => $argument) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $argument), "admin $ability");
            $this->assertFalse(Gate::forUser($client)->allows($ability, $argument), "client $ability");
            $this->assertFalse(Gate::forUser(null)->allows($ability, $argument), "guest $ability");
        }
    }

    // --- category and swatch ---

    public function test_the_category_of_an_item_must_match_its_group(): void
    {
        $group = OptionGroup::factory()->create(['category' => 'exterior']);

        $this->expectException(InvalidArgumentException::class);

        EquipmentItem::factory()->create(['group_id' => $group->id, 'category' => 'safety']);
    }

    public function test_moving_an_item_to_a_group_of_another_category_is_rejected(): void
    {
        $exterior = OptionGroup::factory()->create(['category' => 'exterior']);
        $interior = OptionGroup::factory()->create(['category' => 'interior']);
        $item = $this->item($exterior);

        try {
            $item->update(['group_id' => $interior->id]);
            $this->fail('The category must match the new group.');
        } catch (InvalidArgumentException) {
            $this->assertSame($exterior->id, $item->fresh()->group_id);
        }

        // Same category is fine.
        $item->update(['group_id' => OptionGroup::factory()->create(['category' => 'exterior'])->id]);
    }

    public function test_a_swatch_is_allowed_only_for_items_of_a_swatch_group_and_must_be_a_hex_color(): void
    {
        $colors = OptionGroup::factory()->swatch()->create();
        $wheels = OptionGroup::factory()->create();

        $item = $this->item($colors, ['swatch_hex' => '#C62828']);
        $this->assertSame('#C62828', $item->fresh()->swatch_hex);
        $this->item($colors, ['swatch_hex' => '#abcdef']);
        $this->item($colors, ['swatch_hex' => null]);

        foreach (['#FFF', 'FFFFFF', '#GGGGGG', '#FFFFFFF', '#12345', 'red', '', ' #FFFFFF'] as $bad) {
            try {
                $this->item($colors, ['swatch_hex' => $bad]);
                $this->fail("'$bad' must be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // A group without swatches, no group at all, and an update that adds a swatch to them.
        foreach ([fn () => $this->item($wheels, ['swatch_hex' => '#FFFFFF']), fn () => EquipmentItem::factory()->create(['swatch_hex' => '#FFFFFF'])] as $create) {
            try {
                $create();
                $this->fail('A swatch needs a group that uses swatches.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $plain = $this->item($wheels);
        $this->expectException(InvalidArgumentException::class);
        $plain->update(['swatch_hex' => '#FFFFFF']);
    }

    // --- the hook: at most one standard item per single group and trim ---

    public function test_a_second_standard_item_of_a_single_group_on_a_trim_is_rejected(): void
    {
        $group = OptionGroup::factory()->create();
        $trim = Trim::factory()->create();
        [$first, $second] = [$this->item($group), $this->item($group)];

        $this->row($trim, $first, 'standard');

        try {
            $this->row($trim, $second, 'standard');
            $this->fail('A second standard item of a single group must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('demote', $e->getMessage());
        }

        $this->assertSame(1, TrimEquipment::count());
    }

    public function test_the_standard_item_can_be_swapped_by_demoting_first_and_demotion_is_never_blocked(): void
    {
        $group = OptionGroup::factory()->create();
        $trim = Trim::factory()->create();
        [$a, $b] = [$this->item($group), $this->item($group)];
        $rowA = $this->row($trim, $a, 'standard');
        $rowB = $this->row($trim, $b, 'optional', 30_000);

        // Promoting B while A is standard is refused...
        try {
            $rowB->update(['availability' => 'standard', 'price_cents' => null]);
            $this->fail('Two standard items must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(EquipmentAvailability::Optional, $rowB->fresh()->availability);
        }

        // ...demoting A is allowed (a state without a standard item), then B can be promoted.
        $rowA->update(['availability' => 'optional', 'price_cents' => 0]);
        $rowB->update(['availability' => 'standard', 'price_cents' => null]);

        $this->assertSame(EquipmentAvailability::Standard, $rowB->fresh()->availability);
        $this->assertSame(EquipmentAvailability::Optional, $rowA->fresh()->availability);
    }

    public function test_saving_the_standard_row_itself_is_not_blocked(): void
    {
        $group = OptionGroup::factory()->create();
        $trim = Trim::factory()->create();
        $row = $this->row($trim, $this->item($group), 'standard');

        $row->forceFill(['updated_at' => now()->addMinute()])->save();
        $row->update(['availability' => 'standard']);

        $this->assertSame(1, TrimEquipment::count());
    }

    public function test_the_hook_looks_at_one_trim_and_one_group_only(): void
    {
        $group = OptionGroup::factory()->create();
        $other = OptionGroup::factory()->create();
        [$trimA, $trimB] = [Trim::factory()->create(), Trim::factory()->create()];
        [$a, $b, $c] = [$this->item($group), $this->item($group), $this->item($other)];

        $this->row($trimA, $a, 'standard');
        $this->row($trimB, $b, 'standard');       // another trim
        $this->row($trimA, $c, 'standard');       // another group
        $this->row($trimA, EquipmentItem::factory()->create(), 'standard'); // no group
        $this->row($trimA, EquipmentItem::factory()->create(), 'standard');

        $this->assertSame(5, TrimEquipment::count());
    }

    public function test_a_multiple_group_has_no_such_rule(): void
    {
        $group = OptionGroup::factory()->multiple()->create();
        $trim = Trim::factory()->create();

        $this->row($trim, $this->item($group), 'standard');
        $this->row($trim, $this->item($group), 'standard');

        $this->assertSame(2, TrimEquipment::count());
        $this->assertSame([], OptionGroupRule::problemsFor($group, $trim));
    }

    // --- the rule on the final state ---

    public function test_the_rule_accepts_exactly_one_standard_item_and_surcharges(): void
    {
        $this->assertSame([], OptionGroupRule::problems([]));
        $this->assertSame([], OptionGroupRule::problems([
            ['item' => 'A', 'availability' => 'standard', 'price' => null],
        ]));
        $this->assertSame([], OptionGroupRule::problems([
            ['item' => 'A', 'availability' => 'standard', 'price' => null],
            ['item' => 'B', 'availability' => 'optional', 'price' => 0],
            ['item' => 'C', 'availability' => 'optional', 'price' => 90_000],
        ]));
    }

    public function test_the_rule_reports_every_bad_case(): void
    {
        $codes = fn (array $entries) => array_column(OptionGroupRule::problems($entries), 'code');

        $this->assertSame([OptionGroupRule::NO_STANDARD], $codes([
            ['item' => 'A', 'availability' => 'optional', 'price' => 0],
            ['item' => 'B', 'availability' => 'optional', 'price' => 100],
        ]));

        $many = OptionGroupRule::problems([
            ['item' => 'A', 'availability' => 'standard', 'price' => null],
            ['item' => 'B', 'availability' => 'standard', 'price' => null],
            ['item' => 'C', 'availability' => 'optional', 'price' => 100],
        ]);
        $this->assertSame(OptionGroupRule::MANY_STANDARD, $many[0]['code']);
        $this->assertSame(['A', 'B'], $many[0]['items']);

        $this->assertSame([OptionGroupRule::STANDARD_WITH_PRICE], $codes([
            ['item' => 'A', 'availability' => 'standard', 'price' => 500],
        ]));

        foreach ([null, -1] as $price) {
            $this->assertSame([OptionGroupRule::BAD_SURCHARGE], $codes([
                ['item' => 'A', 'availability' => 'standard', 'price' => null],
                ['item' => 'B', 'availability' => 'optional', 'price' => $price],
            ]));
        }
    }

    public function test_the_rule_checks_the_database_state_of_a_group_on_a_trim(): void
    {
        $group = OptionGroup::factory()->create();
        [$trim, $other] = [Trim::factory()->create(), Trim::factory()->create()];
        [$a, $b] = [$this->item($group), $this->item($group)];

        // A trim without entries does not offer the group.
        $this->assertSame([], OptionGroupRule::problemsFor($group, $trim));

        $this->row($trim, $b, 'optional', 50_000);
        $this->assertSame([OptionGroupRule::NO_STANDARD], array_column(OptionGroupRule::problemsFor($group, $trim), 'code'));

        $this->row($trim, $a, 'standard');
        $this->assertSame([], OptionGroupRule::problemsFor($group, $trim));

        // Another trim is judged on its own entries.
        $this->assertSame([], OptionGroupRule::problemsFor($group, $other));
    }

    // --- translations ---

    public function test_the_new_log_fields_and_actions_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertNotEmpty($translations["activity.action.option_group.$event"] ?? null, $event);
        }

        // Same lookup rule as resources/js/lib/activity.js: entity-specific key, then the generic one.
        $label = fn (string $entity, string $field) => $translations["activity.field.$entity.$field"] ?? $translations["activity.field.$field"] ?? null;
        $ignored = config('activity-log.ignored');

        foreach (['option_groups' => 'option_group', 'equipment_items' => 'equipment_item'] as $table => $entity) {
            foreach (array_diff(Schema::getColumnListing($table), $ignored) as $column) {
                $this->assertNotEmpty($label($entity, $column), "no label for $entity.$column");
            }
        }

        $this->assertNotEmpty($translations['activity.field.catalog.option_group'] ?? null);
        $this->assertNotEmpty($translations['option.selection.single'] ?? null);
        $this->assertNotEmpty($translations['option.selection.multiple'] ?? null);
    }
}
