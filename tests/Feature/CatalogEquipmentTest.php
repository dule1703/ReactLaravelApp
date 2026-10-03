<?php

namespace Tests\Feature;

use App\Enums\EquipmentAvailability;
use App\Enums\EquipmentCategory;
use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use App\Policies\AdminOnlyPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CatalogEquipmentTest extends TestCase
{
    use RefreshDatabase;

    // --- relations ---

    public function test_relations_work_in_both_directions(): void
    {
        $row = TrimEquipment::factory()->optional(150_000)->create();
        $trim = $row->trim;
        $item = $row->equipmentItem;

        $this->assertTrue($trim->trimEquipment->first()->is($row));
        $this->assertTrue($item->trimEquipment->first()->is($row));
        $this->assertTrue($trim->equipment->first()->is($item));
        $this->assertTrue($item->trims->first()->is($trim));
    }

    public function test_the_equipment_relation_exposes_the_pivot_fields(): void
    {
        $row = TrimEquipment::factory()->optional(150_000)->create();

        $pivot = $row->trim->equipment->first()->pivot;
        $this->assertSame(EquipmentAvailability::Optional, $pivot->availability);
        $this->assertSame(150_000, $pivot->price_cents);
        $this->assertSame($row->id, $pivot->id);

        $fromItem = $row->equipmentItem->trims->first()->pivot;
        $this->assertSame(150_000, $fromItem->price_cents);
    }

    public function test_the_same_item_can_be_on_many_trims_and_a_trim_has_many_items(): void
    {
        $item = EquipmentItem::factory()->create();
        TrimEquipment::factory()->count(2)->create(['equipment_item_id' => $item->id]);
        $trim = Trim::factory()->create();
        TrimEquipment::factory()->count(3)->create(['trim_id' => $trim->id]);

        $this->assertCount(2, $item->trims);
        $this->assertCount(3, $trim->equipment);
    }

    public function test_enums_are_cast(): void
    {
        $item = EquipmentItem::create(['name' => 'ESP', 'category' => 'safety'])->fresh();

        $this->assertSame(EquipmentCategory::Safety, $item->category);
        $this->assertTrue($item->is_active);
    }

    // --- unique / restrict ---

    public function test_item_name_is_globally_unique(): void
    {
        EquipmentItem::factory()->create(['name' => 'Parking senzori', 'category' => 'comfort']);

        $this->expectException(QueryException::class);

        EquipmentItem::factory()->create(['name' => 'Parking senzori', 'category' => 'safety']);
    }

    public function test_an_item_can_be_attached_to_a_trim_only_once(): void
    {
        $row = TrimEquipment::factory()->create();

        $this->expectException(QueryException::class);

        TrimEquipment::factory()->create([
            'trim_id' => $row->trim_id,
            'equipment_item_id' => $row->equipment_item_id,
        ]);
    }

    public function test_items_and_trims_with_equipment_links_cannot_be_deleted(): void
    {
        $row = TrimEquipment::factory()->create();

        foreach ([$row->equipmentItem, $row->trim] as $parent) {
            try {
                $parent->delete();
                $this->fail(class_basename($parent).' with equipment links must not be deletable.');
            } catch (QueryException) {
                $this->assertNotNull($parent->fresh());
            }
        }

        $row->delete();
        $row->equipmentItem->delete();
        $this->assertSame(0, EquipmentItem::count());
    }

    // --- price rule ---

    public function test_standard_equipment_must_not_have_a_price(): void
    {
        $trim = Trim::factory()->create();
        $item = EquipmentItem::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        TrimEquipment::create([
            'trim_id' => $trim->id,
            'equipment_item_id' => $item->id,
            'availability' => 'standard',
            'price_cents' => 0,
        ]);
    }

    public function test_optional_equipment_needs_a_price_of_zero_or_more(): void
    {
        $trim = Trim::factory()->create();
        $attributes = fn (?int $price) => [
            'trim_id' => $trim->id,
            'equipment_item_id' => EquipmentItem::factory()->create()->id,
            'availability' => 'optional',
            'price_cents' => $price,
        ];

        foreach ([null, -1] as $invalid) {
            try {
                TrimEquipment::create($attributes($invalid));
                $this->fail('Optional equipment with price '.var_export($invalid, true).' must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // 0 is a free option, not "no price".
        $free = TrimEquipment::create($attributes(0))->fresh();
        $this->assertSame(0, $free->price_cents);
        $this->assertSame(EquipmentAvailability::Optional, $free->availability);
        $this->assertSame(1, TrimEquipment::count());
    }

    public function test_standard_without_a_price_is_valid(): void
    {
        $row = TrimEquipment::factory()->create();

        $this->assertNull($row->fresh()->price_cents);
        $this->assertSame(EquipmentAvailability::Standard, $row->fresh()->availability);
    }

    public function test_the_rule_also_applies_to_updates(): void
    {
        $row = TrimEquipment::factory()->optional(150_000)->create();

        // Becoming standard while keeping the price is invalid...
        try {
            $row->update(['availability' => 'standard']);
            $this->fail('Standard with a price must be rejected on update.');
        } catch (InvalidArgumentException) {
            $this->assertSame(EquipmentAvailability::Optional, $row->fresh()->availability);
        }

        // ...and so is dropping the price of an optional item.
        $this->expectException(InvalidArgumentException::class);
        $row->fresh()->update(['price_cents' => null]);
    }

    // --- active scope and order ---

    public function test_active_equipment_excludes_inactive_items(): void
    {
        $trim = Trim::factory()->create();
        $active = TrimEquipment::factory()->create(['trim_id' => $trim->id]);
        TrimEquipment::factory()->create([
            'trim_id' => $trim->id,
            'equipment_item_id' => EquipmentItem::factory()->inactive(),
        ]);

        $this->assertCount(2, $trim->equipment);
        $this->assertSame([$active->equipment_item_id], $trim->activeEquipment()->pluck('equipment_items.id')->all());
        $this->assertSame(1, EquipmentItem::active()->count());
    }

    public function test_active_equipment_is_ordered_by_category_case_order_then_sort_order(): void
    {
        $trim = Trim::factory()->create();
        $expected = [];

        // Created in reverse of the expected order; alphabetical order of the category strings
        // (comfort, driving, exterior, ...) differs from the enum order on purpose.
        foreach (array_reverse(EquipmentCategory::cases()) as $category) {
            foreach ([20, 10] as $sortOrder) {
                $item = EquipmentItem::factory()->create([
                    'category' => $category,
                    'sort_order' => $sortOrder,
                    'name' => "{$category->value} $sortOrder",
                ]);
                TrimEquipment::factory()->create(['trim_id' => $trim->id, 'equipment_item_id' => $item->id]);
            }
        }
        foreach (EquipmentCategory::cases() as $category) {
            $expected[] = "{$category->value} 10";
            $expected[] = "{$category->value} 20";
        }

        $this->assertSame($expected, $trim->activeEquipment()->pluck('equipment_items.name')->all());
        $this->assertSame(
            array_map(fn (EquipmentCategory $c) => $c->value, EquipmentCategory::cases()),
            ['safety', 'comfort', 'exterior', 'interior', 'multimedia', 'driving'],
        );
    }

    // --- prices and logging ---

    public function test_prices_are_integer_cents_and_no_column_is_floating_point(): void
    {
        $row = TrimEquipment::factory()->optional(9_000_000_000)->create();

        $this->assertSame(9_000_000_000, $row->fresh()->price_cents);
        $this->assertIsInt(DB::table('trim_equipment')->where('id', $row->id)->value('price_cents'));

        foreach (['equipment_items', 'trim_equipment'] as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $this->assertNotContains(
                    strtolower($column['type_name']),
                    ['float', 'double', 'decimal', 'numeric', 'real'],
                    "$table.{$column['name']} must not be floating point",
                );
            }
        }
    }

    public function test_a_price_change_is_logged_with_old_and_new_value(): void
    {
        $row = TrimEquipment::factory()->optional(150_000)->create();

        $row->update(['price_cents' => 200_000]);

        $log = ActivityLog::where('action', 'trim_equipment.updated')->where('subject_id', $row->id)->sole();
        $this->assertSame(['price_cents'], array_keys($log->changes));
        $this->assertEquals(['old' => 150_000, 'new' => 200_000], $log->changes['price_cents']);
    }

    public function test_an_availability_change_is_logged_with_old_and_new_value(): void
    {
        $row = TrimEquipment::factory()->optional(150_000)->create();

        $row->update(['availability' => 'standard', 'price_cents' => null]);

        $log = ActivityLog::where('action', 'trim_equipment.updated')->where('subject_id', $row->id)->sole();
        $this->assertEquals(['old' => 'optional', 'new' => 'standard'], $log->changes['availability']);
        $this->assertEquals(['old' => 150_000, 'new' => null], $log->changes['price_cents']);
    }

    public function test_equipment_changes_are_logged(): void
    {
        $item = EquipmentItem::factory()->create();
        $item->update(['is_active' => false]);
        $item->delete();

        $this->assertSame(
            ['equipment_item.created', 'equipment_item.updated', 'equipment_item.deleted'],
            ActivityLog::where('subject_type', $item->getMorphClass())->orderBy('id')->pluck('action')->all(),
        );
    }

    // --- policies ---

    public function test_policy_matrix_for_the_equipment_entities(): void
    {
        $records = [EquipmentItem::factory()->create(), TrimEquipment::factory()->create()];
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();

        foreach ($records as $record) {
            $name = class_basename($record);
            $this->assertInstanceOf(AdminOnlyPolicy::class, Gate::getPolicyFor($record), $name);

            foreach (['viewAny' => $record::class, 'create' => $record::class, 'view' => $record, 'update' => $record, 'delete' => $record] as $ability => $argument) {
                $this->assertTrue(Gate::forUser($admin)->allows($ability, $argument), "admin $ability $name");
                $this->assertFalse(Gate::forUser($client)->allows($ability, $argument), "client $ability $name");
                $this->assertFalse(Gate::forUser(null)->allows($ability, $argument), "guest $ability $name");
            }
        }
    }

    // --- activity log translations ---

    /**
     * The label rule itself lives in resources/js/lib/activity.js (tested with vitest); this only
     * checks that the keys it looks up exist in lang/sr_Latn.json.
     */
    public function test_every_catalog_entity_has_activity_translations(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);
        $models = [CarModel::class, Trim::class, Engine::class, Transmission::class, Version::class, EquipmentItem::class, TrimEquipment::class];

        foreach ($models as $class) {
            $entity = Str::snake(class_basename($class));

            foreach (['created', 'updated', 'deleted'] as $event) {
                $this->assertNotEmpty($translations["activity.action.$entity.$event"] ?? null, "activity.action.$entity.$event");
            }

            // Ignored fields (updated_at) are never written to the log, so they need no label.
            foreach (array_diff(Schema::getColumnListing((new $class)->getTable()), config('activity-log.ignored')) as $column) {
                $this->assertTrue(
                    ! empty($translations["activity.field.$entity.$column"]) || ! empty($translations["activity.field.$column"]),
                    "no label for $entity.$column",
                );
            }
        }
    }

    public function test_translated_action_labels_are_not_raw_keys(): void
    {
        foreach (['car_model', 'trim', 'engine', 'transmission', 'version', 'equipment_item', 'trim_equipment'] as $entity) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $key = "activity.action.$entity.$event";
                $this->assertNotSame($key, __($key));
            }
        }
    }
}
