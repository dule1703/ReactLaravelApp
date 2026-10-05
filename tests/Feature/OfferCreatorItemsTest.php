<?php

namespace Tests\Feature;

use App\Enums\EquipmentCategory;
use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\OptionGroup;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use App\Services\OfferCreator;
use App\Services\OfferItemsException;
use App\Services\OfferTotalMismatchException;
use App\Support\OfferCalculator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DatabaseMigrations for the same reason as OfferCreatorTest (a real transaction, the counter).
 */
class OfferCreatorItemsTest extends TestCase
{
    use DatabaseMigrations;

    private const JMBG = '0101990710006';

    private OfferCreator $creator;

    private User $client;

    private Version $version;

    private EquipmentItem $climatronic;

    private EquipmentItem $metallic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
        $this->creator = app(OfferCreator::class);

        $this->client = User::factory()->client()->create();
        $this->client->profile()->forceFill([
            'full_name' => 'Petar Petrović', 'jmbg' => self::JMBG, 'address' => 'Knez Mihailova 1',
            'postal_code' => '11000', 'city' => 'Beograd', 'country' => 'RS',
        ])->save();
        $this->client = $this->client->fresh();

        $trim = Trim::factory()->create([
            'car_model_id' => CarModel::factory()->create(['name' => 'Octavia']),
            'name' => 'Style',
        ]);
        $this->version = Version::factory()->create([
            'trim_id' => $trim->id,
            'engine_id' => Engine::factory()->create(),
            'transmission_id' => Transmission::factory()->create(),
            'base_price_cents' => 2_500_000,
        ]);

        $this->climatronic = $this->extra(150_000, null, 'Climatronic');

        $paint = OptionGroup::factory()->create(['name' => 'Boja karoserije', 'category' => EquipmentCategory::Exterior]);
        TrimEquipment::factory()->create([
            'trim_id' => $trim->id,
            'equipment_item_id' => EquipmentItem::factory()->create(['group_id' => $paint->id, 'category' => $paint->category, 'name' => 'Bela']),
        ]);
        $this->metallic = $this->extra(120_000, $paint, 'Metalik');
    }

    private function extra(int $price, ?OptionGroup $group = null, string $name = 'Oprema'): EquipmentItem
    {
        $item = EquipmentItem::factory()->create(array_merge(
            ['name' => $name],
            $group ? ['group_id' => $group->id, 'category' => $group->category] : ['category' => EquipmentCategory::Comfort],
        ));

        TrimEquipment::factory()->optional($price)->create(['trim_id' => $this->version->trim_id, 'equipment_item_id' => $item->id]);

        return $item;
    }

    /** @return list<array{version_id: int, quantity: int, option_ids: list<int>}> */
    private function choice(int $quantity = 2): array
    {
        return [['version_id' => $this->version->id, 'quantity' => $quantity, 'option_ids' => [$this->climatronic->id, $this->metallic->id]]];
    }

    /** What the offer must cost: (2,500,000 + 150,000 + 120,000) x 2 = 5,540,000 net. */
    private function expectedTotals(): array
    {
        return OfferCalculator::calculate([[
            'version_price_cents' => 2_500_000,
            'quantity' => 2,
            'options' => [['price_cents' => 150_000], ['price_cents' => 120_000]],
        ]], 2000);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, OfferItem::count());
        $this->assertSame(0, OfferItemOption::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_the_amounts_are_the_result_of_the_calculator_and_the_rows_are_written(): void
    {
        $offer = $this->creator->create($this->client, 'Za firmu', $this->choice())->fresh();
        $totals = $this->expectedTotals();

        $this->assertSame(5_540_000, $totals['total_net_cents']);
        $this->assertSame($totals['total_net_cents'], $offer->total_net_cents);
        $this->assertSame($totals['vat_cents'], $offer->vat_cents);
        $this->assertSame($totals['total_gross_cents'], $offer->total_gross_cents);
        $this->assertSame(2000, $offer->vat_rate_bp);

        $item = $offer->items->sole();
        $this->assertSame(1, $item->position);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('Octavia', $item->car_model_name);
        $this->assertSame('Style', $item->trim_name);
        $this->assertSame(2_500_000, $item->version_price_cents);
        $this->assertSame(5_540_000, $item->line_net_cents);

        $options = $item->options;
        $this->assertSame([1, 2], $options->pluck('position')->all());
        $this->assertEqualsCanonicalizing(['Climatronic', 'Metalik'], $options->pluck('name')->all());

        $metallic = $options->firstWhere('name', 'Metalik');
        $this->assertTrue($metallic->is_surcharge);
        $this->assertSame('Boja karoserije', $metallic->group_name);
        $this->assertSame(120_000, $metallic->price_cents);
        $this->assertFalse($options->firstWhere('name', 'Climatronic')->is_surcharge);
    }

    public function test_the_surcharge_is_added_to_the_line_not_a_replacement(): void
    {
        $offer = $this->creator->create($this->client, null, [[
            'version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => [$this->metallic->id],
        ]]);

        $this->assertSame(2_620_000, $offer->items->sole()->line_net_cents);
    }

    public function test_the_snapshot_does_not_follow_the_catalog(): void
    {
        $offer = $this->creator->create($this->client, null, $this->choice());
        $before = [$offer->fresh()->toArray(), OfferItem::all()->toArray(), OfferItemOption::all()->toArray()];

        $this->version->update(['base_price_cents' => 9_999_900]);
        $this->version->trim->carModel->update(['name' => 'Renamed']);
        $this->version->trim->update(['name' => 'Renamed trim']);
        $this->climatronic->update(['is_active' => false, 'name' => 'Renamed item']);
        TrimEquipment::where('equipment_item_id', $this->metallic->id)->get()->each->delete();
        $this->metallic->group->update(['is_active' => false]);

        $after = [$offer->fresh()->toArray(), OfferItem::all()->toArray(), OfferItemOption::all()->toArray()];

        $this->assertSame($before, $after);
    }

    public function test_an_invalid_item_in_the_middle_writes_nothing_and_spends_no_number(): void
    {
        $items = [
            $this->choice()[0],
            ['version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => [999_999]],
        ];

        try {
            $this->creator->create($this->client, null, $items);
            $this->fail('The second item is invalid.');
        } catch (OfferItemsException $e) {
            $this->assertArrayHasKey('items.1.option_ids.0', $e->errors());
        }

        $this->assertNothingWritten();
    }

    public function test_wrong_types_from_the_client_are_a_validation_error_and_write_nothing(): void
    {
        try {
            $this->creator->create($this->client, null, [['version_id' => '1', 'quantity' => 'x', 'option_ids' => 'y']]);
            $this->fail('The input is invalid.');
        } catch (OfferItemsException $e) {
            $this->assertEqualsCanonicalizing(['items.0.version_id', 'items.0.quantity', 'items.0.option_ids'], array_keys($e->errors()));
        }

        $this->assertNothingWritten();
    }

    public function test_an_amount_over_the_limits_is_a_validation_error_not_a_calculator_error(): void
    {
        $this->version->update(['base_price_cents' => 1_000_000_000]);

        try {
            $this->creator->create($this->client, null, [['version_id' => $this->version->id, 'quantity' => 999, 'option_ids' => []]]);
            $this->fail('The total is over the limit.');
        } catch (OfferItemsException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $this->assertNothingWritten();
    }

    public function test_a_different_expected_total_is_a_mismatch_with_the_server_amounts_and_nothing_is_written(): void
    {
        $totals = $this->expectedTotals();

        try {
            $this->creator->create($this->client, null, $this->choice(), [
                'expected_total_net_cents' => $totals['total_net_cents'] + 1,
                'expected_total_gross_cents' => $totals['total_gross_cents'],
            ]);
            $this->fail('The totals differ.');
        } catch (OfferTotalMismatchException $e) {
            $this->assertSame($totals['total_net_cents'], $e->totalNetCents);
            $this->assertSame($totals['vat_cents'], $e->vatCents);
            $this->assertSame($totals['total_gross_cents'], $e->totalGrossCents);
            $this->assertSame(2000, $e->vatRateBp);
        }

        $this->assertNothingWritten();
    }

    public function test_missing_or_wrongly_typed_expected_values_are_a_mismatch(): void
    {
        foreach ([[], ['expected_total_net_cents' => '5540000', 'expected_total_gross_cents' => '6648000']] as $expected) {
            try {
                $this->creator->create($this->client, null, $this->choice(), $expected);
                $this->fail('The expected totals are not valid.');
            } catch (OfferTotalMismatchException) {
                // expected
            }
        }

        $this->assertNothingWritten();
    }

    public function test_a_vat_rate_change_after_the_client_saw_the_totals_is_a_mismatch_through_gross(): void
    {
        $seen = $this->expectedTotals();

        Setting::setVatRateBp(2500);

        try {
            $this->creator->create($this->client, null, $this->choice(), [
                'expected_total_net_cents' => $seen['total_net_cents'],
                'expected_total_gross_cents' => $seen['total_gross_cents'],
            ]);
            $this->fail('The gross total changed with the rate.');
        } catch (OfferTotalMismatchException $e) {
            $this->assertSame(2500, $e->vatRateBp);
            $this->assertSame(OfferCalculator::calculate([[
                'version_price_cents' => 2_500_000, 'quantity' => 2,
                'options' => [['price_cents' => 150_000], ['price_cents' => 120_000]],
            ]], 2500)['total_gross_cents'], $e->totalGrossCents);
        }

        $this->assertNothingWritten();
    }

    public function test_a_catalog_price_change_between_seen_and_create_is_a_mismatch(): void
    {
        $seen = $this->expectedTotals();

        TrimEquipment::where('equipment_item_id', $this->climatronic->id)->firstOrFail()->update(['price_cents' => 160_000]);

        $this->expectException(OfferTotalMismatchException::class);

        $this->creator->create($this->client, null, $this->choice(), [
            'expected_total_net_cents' => $seen['total_net_cents'],
            'expected_total_gross_cents' => $seen['total_gross_cents'],
        ]);
    }

    public function test_matching_expected_totals_write_the_offer_with_the_server_amounts(): void
    {
        $totals = $this->expectedTotals();

        $offer = $this->creator->create($this->client, null, $this->choice(), [
            'expected_total_net_cents' => $totals['total_net_cents'],
            'expected_total_gross_cents' => $totals['total_gross_cents'],
        ])->fresh();

        $this->assertSame('001/2026', $offer->number);
        $this->assertSame($totals['total_gross_cents'], $offer->total_gross_cents);
        $this->assertSame(1, OfferItem::count());
    }

    public function test_one_summary_entry_and_no_entry_per_item_or_option(): void
    {
        $offer = $this->creator->create($this->client, null, $this->choice());

        $this->assertSame(1, ActivityLog::where('action', 'offer.items_created')->count());
        $this->assertSame(1, ActivityLog::where('action', 'offer.created')->count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['offer_item.created', 'offer_item_option.created'])->count());

        $log = ActivityLog::where('action', 'offer.items_created')->sole();
        $this->assertSame($offer->id, $log->subject_id);
        $this->assertStringContainsString('001/2026', $log->subject_label);
        $this->assertSame(['items_count' => 1, 'options_count' => 2], $log->changes);
        $this->assertStringNotContainsString('Climatronic', json_encode($log->toArray()));
        $this->assertStringNotContainsString(self::JMBG, json_encode(ActivityLog::all()->toArray()));
    }

    public function test_an_offer_without_items_writes_no_summary_entry(): void
    {
        $this->creator->create($this->client);

        $this->assertSame(0, ActivityLog::where('action', 'offer.items_created')->count());
    }

    public function test_updates_of_items_and_options_stay_logged(): void
    {
        $offer = $this->creator->create($this->client, null, $this->choice());

        $offer->items->sole()->update(['quantity' => 3]);
        $offer->items->sole()->options->first()->update(['price_cents' => 1]);

        $this->assertSame(1, ActivityLog::where('action', 'offer_item.updated')->count());
        $this->assertSame(1, ActivityLog::where('action', 'offer_item_option.updated')->count());
    }

    public function test_the_mute_flag_is_not_an_attribute_and_cannot_come_from_a_request(): void
    {
        $item = (new OfferItem)->fill(['quantity' => 1, 'creationLogMuted' => true, 'muteCreationLog' => true]);

        $this->assertFalse($item->activityMuted('created'));
        $this->assertArrayNotHasKey('creationLogMuted', $item->toArray());

        $muted = (new OfferItem)->muteCreationLog();
        $this->assertTrue($muted->activityMuted('created'));
        $this->assertFalse($muted->activityMuted('updated'));
        $this->assertFalse($muted->activityMuted('deleted'));
        $this->assertStringNotContainsString('Muted', $muted->toJson());
    }

    public function test_the_limits_apply_to_the_creator_too(): void
    {
        try {
            $this->creator->create($this->client, null, array_fill(0, 21, $this->choice(1)[0]));
            $this->fail('Too many items.');
        } catch (OfferItemsException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $this->assertNothingWritten();
    }
}
