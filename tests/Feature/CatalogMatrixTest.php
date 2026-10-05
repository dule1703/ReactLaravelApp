<?php

namespace Tests\Feature;

use App\Enums\EquipmentAvailability;
use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use App\Services\EquipmentMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class CatalogMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarModel $model;

    private Trim $essence;

    private Trim $style;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->model = CarModel::factory()->create();
        $this->essence = Trim::factory()->create(['car_model_id' => $this->model->id, 'name' => 'Essence', 'sort_order' => 1]);
        $this->style = Trim::factory()->create(['car_model_id' => $this->model->id, 'name' => 'Style', 'sort_order' => 2]);
    }

    private function as(): static
    {
        return $this->actingAs($this->admin);
    }

    private function row(Trim $trim, EquipmentItem $item, string $availability = 'standard', ?int $price = null): TrimEquipment
    {
        return TrimEquipment::create([
            'trim_id' => $trim->id,
            'equipment_item_id' => $item->id,
            'availability' => $availability,
            'price_cents' => $price,
        ]);
    }

    private function find(Trim $trim, EquipmentItem $item): ?TrimEquipment
    {
        return TrimEquipment::query()->where('trim_id', $trim->id)->where('equipment_item_id', $item->id)->first();
    }

    /**
     * The state of a cell as the browser sees it.
     *
     * @return array{availability: string, price_cents: ?int}
     */
    private function seen(Trim $trim, EquipmentItem $item): array
    {
        $row = $this->find($trim, $item);

        return ['availability' => $row?->availability->value ?? 'none', 'price_cents' => $row?->price_cents];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function cell(Trim $trim, EquipmentItem $item, string $target, array $extra = [], ?array $expected = null)
    {
        return $this->as()->putJson('/admin/catalog/matrix/cell', $extra + [
            'car_model_id' => $trim->car_model_id,
            'trim_id' => $trim->id,
            'equipment_item_id' => $item->id,
            'availability' => $target,
            'expected' => $expected ?? $this->seen($trim, $item),
        ]);
    }

    /** @return array{0: OptionGroup, 1: EquipmentItem, 2: EquipmentItem, 3: EquipmentItem} group + 3 items */
    private function singleGroup(string $selection = 'single'): array
    {
        $group = OptionGroup::factory()->create(['name' => 'Točkovi', 'selection' => $selection]);
        $items = [];
        foreach (['16', '17', '18'] as $size) {
            $items[] = EquipmentItem::factory()->create(['name' => "Felne $size", 'category' => $group->category, 'group_id' => $group->id]);
        }

        return [$group, ...$items];
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403(): void
    {
        $requests = [['get', '/admin/catalog/matrix'], ['put', '/admin/catalog/matrix/cell'], ['delete', '/admin/catalog/matrix/group']];

        foreach ($requests as [$method, $url]) {
            $this->assertSame(302, $this->{$method}($url)->getStatusCode(), "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($requests as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }
    }

    // --- display ---

    public function test_the_matrix_shows_the_trims_of_the_selected_model_and_items_in_category_order(): void
    {
        $other = Trim::factory()->create(['name' => 'Tuđa linija']);
        $multimedia = EquipmentItem::factory()->create(['name' => 'Radio', 'category' => 'multimedia', 'sort_order' => 1]);
        $safety = EquipmentItem::factory()->create(['name' => 'ABS', 'category' => 'safety', 'sort_order' => 5]);
        $safety2 = EquipmentItem::factory()->create(['name' => 'ESP', 'category' => 'safety', 'sort_order' => 2]);
        $this->row($this->essence, $multimedia);
        $this->row($this->essence, $safety);
        $this->row($this->style, $safety2, 'optional', 10000);
        $this->row($other, $safety);

        $this->as()->get('/admin/catalog/matrix?model='.$this->model->id)->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Matrix')
            ->where('trims.0.name', 'Essence')
            ->where('trims.0.standard_count', 2)
            ->where('trims.1.optional_count', 1)
            ->has('trims', 2)
            ->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['ESP', 'ABS', 'Radio'])
            ->has('entries', 3));
    }

    public function test_by_default_only_items_with_an_entry_for_the_model_are_shown(): void
    {
        $used = EquipmentItem::factory()->create(['name' => 'Korišćena']);
        EquipmentItem::factory()->create(['name' => 'Nekorišćena']);
        $this->row($this->essence, $used);

        $this->as()->get('/admin/catalog/matrix?model='.$this->model->id)
            ->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['Korišćena'])->where('filters.entered', true));

        $this->as()->get('/admin/catalog/matrix?entered=0&model='.$this->model->id)
            ->assertInertia(fn (Assert $page) => $page->has('items', 2)->where('filters.entered', false));
    }

    public function test_the_filters_narrow_the_items(): void
    {
        [$group, $a] = $this->singleGroup();
        $free = EquipmentItem::factory()->create(['name' => 'Klima', 'category' => 'comfort']);
        $url = '/admin/catalog/matrix?entered=0';

        $this->as()->get($url.'&category=comfort')->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['Klima']));
        $this->as()->get($url.'&group='.$group->id)->assertInertia(fn (Assert $page) => $page->has('items', 3));
        $this->as()->get($url.'&group=none')->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['Klima']));
        $this->as()->get($url.'&q=Felne%2017')->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['Felne 17']));
        $this->assertNotNull($free);
    }

    public function test_inactive_items_and_groups_are_marked_as_locked(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $inactive = EquipmentItem::factory()->inactive()->create(['name' => 'Ugašena']);
        $group->update(['is_active' => false]);

        $this->as()->get('/admin/catalog/matrix?entered=0')->assertInertia(fn (Assert $page) => $page
            ->where('items', fn ($items) => collect($items)->firstWhere('name', 'Ugašena')['locked'] === 'item'
                && collect($items)->firstWhere('name', 'Felne 16')['locked'] === 'group'));
        $this->assertNotNull($inactive);
    }

    public function test_the_matrix_is_loaded_with_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as()->get('/admin/catalog/matrix?model='.$this->model->id)->assertOk();

            return count(DB::getQueryLog());
        };

        [$group, $a, $b, $c] = $this->singleGroup();
        $this->row($this->essence, $a);
        $small = $count();

        foreach ([$b, $c] as $item) {
            $this->row($this->essence, $item, 'optional', 5000);
            $this->row($this->style, $item, 'optional', 7000);
        }
        foreach (range(1, 10) as $ignored) {
            $this->row($this->style, EquipmentItem::factory()->create());
        }

        $this->assertSame($small, $count());
    }

    // --- cell states and transitions ---

    public function test_a_free_item_goes_unavailable_standard_optional_and_back(): void
    {
        $item = EquipmentItem::factory()->create();

        $this->cell($this->essence, $item, 'standard')->assertOk();
        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $item)->availability);
        $this->assertNull($this->find($this->essence, $item)->price_cents);

        $this->cell($this->essence, $item, 'optional', ['mode' => 'net', 'amount' => '412'])->assertOk();
        $row = $this->find($this->essence, $item);
        $this->assertSame(EquipmentAvailability::Optional, $row->availability);
        $this->assertSame(41200, $row->price_cents);

        $this->cell($this->essence, $item, 'standard')->assertOk();
        $this->assertNull($this->find($this->essence, $item)->price_cents);

        $this->cell($this->essence, $item, 'none')->assertOk();
        $this->assertNull($this->find($this->essence, $item));
    }

    public function test_the_gross_price_is_converted_by_the_server_and_a_free_option_is_allowed(): void
    {
        $item = EquipmentItem::factory()->create();

        $this->cell($this->essence, $item, 'optional', ['mode' => 'gross', 'amount' => '494,40', 'price_cents' => 1])->assertOk();
        $this->assertSame(41200, $this->find($this->essence, $item)->price_cents);

        $this->cell($this->essence, $item, 'optional', ['mode' => 'net', 'amount' => '0'])->assertOk();
        $this->assertSame(0, $this->find($this->essence, $item)->price_cents);
    }

    public function test_bad_prices_are_rejected_and_nothing_changes(): void
    {
        $item = EquipmentItem::factory()->create();

        foreach (['-5', 'abc', '', '999999999999'] as $amount) {
            $this->cell($this->essence, $item, 'optional', ['mode' => 'net', 'amount' => $amount])->assertJsonValidationErrors('amount');
        }
        $this->cell($this->essence, $item, 'optional', ['mode' => 'other', 'amount' => '5'])->assertJsonValidationErrors('mode');
        $this->cell($this->essence, $item, 'optional')->assertJsonValidationErrors(['mode', 'amount']);

        $this->assertNull($this->find($this->essence, $item));
    }

    public function test_a_standard_item_never_gets_a_price_even_if_one_is_sent(): void
    {
        $item = EquipmentItem::factory()->create();

        $this->cell($this->essence, $item, 'standard', ['mode' => 'net', 'amount' => '99'])->assertOk();

        $this->assertNull($this->find($this->essence, $item)->price_cents);
    }

    public function test_a_trim_of_another_model_is_rejected(): void
    {
        $foreign = Trim::factory()->create();
        $item = EquipmentItem::factory()->create();

        $this->as()->putJson('/admin/catalog/matrix/cell', [
            'car_model_id' => $this->model->id, 'trim_id' => $foreign->id, 'equipment_item_id' => $item->id,
            'availability' => 'standard', 'expected' => ['availability' => 'none'],
        ])->assertJsonValidationErrors('trim_id');

        $this->assertNull($this->find($foreign, $item));
    }

    public function test_an_inactive_item_cannot_be_newly_assigned_but_can_be_removed(): void
    {
        $inactive = EquipmentItem::factory()->inactive()->create();

        $this->cell($this->essence, $inactive, 'standard')->assertJsonValidationErrors('availability');
        $this->assertNull($this->find($this->essence, $inactive));

        $this->row($this->essence, $inactive);
        $this->cell($this->essence, $inactive, 'none')->assertOk();
        $this->assertNull($this->find($this->essence, $inactive));
    }

    public function test_an_item_of_an_inactive_group_cannot_be_assigned(): void
    {
        [$group, $a] = $this->singleGroup();
        $group->update(['is_active' => false]);

        $this->cell($this->essence, $a, 'standard')->assertJsonValidationErrors('availability');
    }

    public function test_items_without_a_group_and_of_multiple_groups_have_no_extra_rules(): void
    {
        [$group, $a, $b] = $this->singleGroup('multiple');

        $this->cell($this->essence, $a, 'optional', ['mode' => 'net', 'amount' => '100'])->assertOk();
        $this->cell($this->essence, $b, 'standard')->assertOk();
        $this->cell($this->essence, $b, 'none')->assertOk();
        $this->assertSame(1, TrimEquipment::count());
    }

    // --- single-choice groups ---

    public function test_the_first_entry_of_a_group_on_a_line_must_be_the_standard_one(): void
    {
        [$group, $a, $b] = $this->singleGroup();

        $this->cell($this->essence, $b, 'optional', ['mode' => 'net', 'amount' => '100'])
            ->assertJsonValidationErrors('availability')
            ->assertJsonFragment(['availability' => [__('First set the standard item of the group.')]]);
        $this->assertSame(0, TrimEquipment::count());

        $this->cell($this->essence, $a, 'standard')->assertOk();
        $this->cell($this->essence, $b, 'optional', ['mode' => 'net', 'amount' => '100'])->assertOk();
        $this->assertSame(10000, $this->find($this->essence, $b)->price_cents);
    }

    public function test_swapping_the_standard_item_makes_the_previous_one_unavailable(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        $this->cell($this->essence, $b, 'standard', [
            'previous' => ['availability' => 'none'], 'expected_standard_item_id' => $a->id,
        ])->assertOk();

        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $b)->availability);
        $this->assertNull($this->find($this->essence, $b)->price_cents);
        $this->assertNull($this->find($this->essence, $a));
    }

    public function test_swapping_the_standard_item_can_make_the_previous_one_a_surcharge(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        $this->cell($this->essence, $b, 'standard', [
            'previous' => ['availability' => 'optional', 'mode' => 'gross', 'amount' => '120'], 'expected_standard_item_id' => $a->id,
        ])->assertOk();

        $previous = $this->find($this->essence, $a);
        $this->assertSame(EquipmentAvailability::Optional, $previous->availability);
        $this->assertSame(10000, $previous->price_cents);
    }

    public function test_swapping_without_saying_what_the_previous_one_becomes_is_refused(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        $this->cell($this->essence, $b, 'standard', ['expected_standard_item_id' => $a->id])->assertJsonValidationErrors('previous');
        $this->cell($this->essence, $b, 'standard', [
            'previous' => ['availability' => 'optional', 'mode' => 'net', 'amount' => '-1'], 'expected_standard_item_id' => $a->id,
        ])->assertJsonValidationErrors('previous.amount');

        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $a)->availability);
    }

    public function test_the_only_standard_item_cannot_be_demoted_while_the_group_has_other_entries(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        $this->cell($this->essence, $a, 'none')
            ->assertJsonValidationErrors('availability')
            ->assertJsonFragment(['availability' => [__('First set another item of the group as the standard one.')]]);
        $this->cell($this->essence, $a, 'optional', ['mode' => 'net', 'amount' => '5'])->assertJsonValidationErrors('availability');

        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $a)->availability);
    }

    public function test_the_only_entry_of_a_group_can_be_removed(): void
    {
        [$group, $a] = $this->singleGroup();
        $this->row($this->essence, $a);

        $this->cell($this->essence, $a, 'none')->assertOk();

        $this->assertSame(0, TrimEquipment::count());
    }

    public function test_a_group_that_is_not_offered_on_a_line_stays_valid(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);

        $this->cell($this->style, $b, 'none')->assertOk();

        $this->assertNull($this->find($this->style, $b));
    }

    public function test_a_whole_group_is_removed_with_a_confirmation_and_one_summary_entry(): void
    {
        [$group, $a, $b, $c] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);
        $this->row($this->style, $a);

        $payload = [
            'car_model_id' => $this->model->id, 'trim_id' => $this->essence->id, 'option_group_id' => $group->id,
            'expected' => [
                ['equipment_item_id' => $a->id, 'availability' => 'standard', 'price_cents' => null],
                ['equipment_item_id' => $b->id, 'availability' => 'optional', 'price_cents' => 10000],
            ],
        ];

        $this->as()->deleteJson('/admin/catalog/matrix/group', $payload)->assertJsonValidationErrors('confirm');
        $this->assertSame(3, TrimEquipment::count());

        $this->as()->deleteJson('/admin/catalog/matrix/group', $payload + ['confirm' => true])->assertOk();

        $this->assertSame(1, TrimEquipment::count());
        $this->assertNotNull($this->find($this->style, $a));
        $summary = ActivityLog::where('action', 'equipment_matrix.changed')->sole();
        $this->assertSame(2, $summary->changes['trim_equipment']['new']);
    }

    public function test_removing_a_group_that_changed_in_the_meantime_is_a_conflict(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        $this->as()->deleteJson('/admin/catalog/matrix/group', [
            'car_model_id' => $this->model->id, 'trim_id' => $this->essence->id, 'option_group_id' => $group->id, 'confirm' => true,
            'expected' => [['equipment_item_id' => $a->id, 'availability' => 'standard', 'price_cents' => null]],
        ])->assertStatus(409);

        $this->assertSame(2, TrimEquipment::count());
    }

    // --- atomicity ---

    public function test_a_failure_in_the_middle_of_a_swap_changes_nothing(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);

        // The second write (promoting the new standard item) fails after the first one (demoting).
        TrimEquipment::updating(function (TrimEquipment $row) use ($b) {
            if ($row->equipment_item_id === $b->id) {
                throw new RuntimeException('boom');
            }
        });

        $this->withoutExceptionHandling();

        try {
            app(EquipmentMatrix::class)->setCell(
                $this->essence, $b->load('group'), 'standard', null,
                ['availability' => 'optional', 'price_cents' => 10000],
                ['availability' => 'none'], $a->id,
            );
            $this->fail('The swap should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $a)->availability);
        $this->assertSame(10000, $this->find($this->essence, $b)->price_cents);
        $this->assertSame(0, ActivityLog::where('action', 'equipment_matrix.changed')->count());
    }

    // --- concurrency ---

    public function test_a_cell_changed_in_the_meantime_is_a_conflict(): void
    {
        $item = EquipmentItem::factory()->create();
        $seen = $this->seen($this->essence, $item);
        $this->row($this->essence, $item);

        $this->cell($this->essence, $item, 'none', expected: $seen)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => __('The matrix has changed in the meantime; refresh it.')]);

        $this->assertNotNull($this->find($this->essence, $item));
    }

    public function test_a_changed_price_is_a_conflict_too(): void
    {
        $item = EquipmentItem::factory()->create();
        $row = $this->row($this->essence, $item, 'optional', 10000);

        $this->cell($this->essence, $item, 'optional', ['mode' => 'net', 'amount' => '50'], ['availability' => 'optional', 'price_cents' => 9999])
            ->assertStatus(409);

        $this->assertSame(10000, $row->fresh()->price_cents);
    }

    public function test_a_swap_over_a_standard_item_that_changed_is_a_conflict(): void
    {
        [$group, $a, $b, $c] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);
        $this->row($this->essence, $c, 'optional', 20000);

        $this->cell($this->essence, $c, 'standard', ['previous' => ['availability' => 'none'], 'expected_standard_item_id' => $b->id])
            ->assertStatus(409);

        $this->assertSame(EquipmentAvailability::Standard, $this->find($this->essence, $a)->availability);
    }

    public function test_repeating_the_same_change_is_a_harmless_no_op(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->row($this->essence, $item);

        $this->cell($this->essence, $item, 'standard')->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'trim_equipment.updated')->count());
    }

    // --- activity log ---

    public function test_row_changes_are_logged_with_names_not_ids(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Climatronic']);

        $this->cell($this->essence, $item, 'optional', ['mode' => 'net', 'amount' => '412'])->assertOk();
        $this->cell($this->essence, $item, 'none')->assertOk();

        $created = ActivityLog::where('action', 'trim_equipment.created')->sole();
        $this->assertSame('Essence · Climatronic', $created->subject_label);
        $this->assertSame(['new' => 41200], $created->changes['price_cents']);
        $this->assertSame('Essence · Climatronic', ActivityLog::where('action', 'trim_equipment.deleted')->sole()->subject_label);
    }

    public function test_a_swap_writes_row_entries_and_one_summary_without_sensitive_values(): void
    {
        [$group, $a, $b] = $this->singleGroup();
        $this->row($this->essence, $a);
        $this->row($this->essence, $b, 'optional', 10000);
        ActivityLog::query()->delete();

        $this->cell($this->essence, $b, 'standard', ['previous' => ['availability' => 'none'], 'expected_standard_item_id' => $a->id])->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'trim_equipment.deleted')->count());
        $this->assertSame(1, ActivityLog::where('action', 'trim_equipment.updated')->count());
        $summary = ActivityLog::where('action', 'equipment_matrix.changed')->sole();
        $this->assertSame($this->essence->id, $summary->subject_id);
        $this->assertSame('Essence', $summary->changes['trim']['new']);
        $this->assertSame('Felne 16, Felne 17', $summary->changes['equipment_item']['new']);
        $this->assertSame(2, $summary->changes['trim_equipment']['new']);
        $this->assertStringContainsString($this->model->name, $summary->description);
    }

    public function test_the_new_actions_and_fields_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);
        $keys = [
            'activity.action.equipment_matrix.changed', 'activity.field.equipment_matrix.trim',
            'activity.field.equipment_matrix.equipment_item', 'activity.field.equipment_matrix.trim_equipment',
            'Matrix', 'Cell', 'Unavailable', 'Standard', 'Extra', 'Optional',
            'The matrix has changed in the meantime; refresh it.', 'First set the standard item of the group.',
            'First set another item of the group as the standard one.', 'Choose what the previous standard item becomes.',
            'The option group would not have exactly one standard item on this trim.', 'The line does not belong to the selected model.',
            'An inactive item (or an item of an inactive group) cannot be assigned.', 'Confirm removing the whole group from the line.',
            'The group was removed from the line.',
        ];

        foreach ($keys as $key) {
            $this->assertNotEmpty($translations[$key] ?? null, $key);
        }
    }

    // --- availability of versions stays as it was ---

    public function test_matrix_changes_do_not_touch_version_availability_or_offerable_items(): void
    {
        $version = Version::factory()->create(['trim_id' => $this->essence->id]);
        $before = Version::available()->pluck('id')->all();
        $item = EquipmentItem::factory()->create();
        $offerable = EquipmentItem::offerable()->pluck('id')->all();

        $this->cell($this->essence, $item, 'standard')->assertOk();
        $this->cell($this->essence, $item, 'none')->assertOk();

        $this->assertSame($before, Version::available()->pluck('id')->all());
        $this->assertSame($offerable, EquipmentItem::offerable()->pluck('id')->all());
        $this->assertContains($version->id, $before);
    }
}
