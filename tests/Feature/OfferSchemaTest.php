<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Engine;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class OfferSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['offers', 'offer_items', 'offer_item_options'];

    /** @return list<string> */
    private function foreignTables(string $table): array
    {
        return array_map(fn (array $key) => $key['foreign_table'], Schema::getForeignKeys($table));
    }

    // --- schema ---

    public function test_the_tables_have_the_snapshot_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('offers', [
            'user_id', 'year', 'seq', 'number', 'offer_date', 'vat_rate_bp', 'note',
            'client_type', 'client_name', 'client_pib', 'client_address', 'client_postal_code', 'client_city', 'client_country',
            'total_net_cents', 'vat_cents', 'total_gross_cents',
        ]));
        $this->assertTrue(Schema::hasColumns('offer_items', [
            'offer_id', 'position', 'quantity', 'car_model_name', 'trim_name', 'engine_name', 'fuel_type',
            'power_kw', 'transmission_name', 'drive', 'version_price_cents', 'line_net_cents',
        ]));
        $this->assertTrue(Schema::hasColumns('offer_item_options', [
            'offer_item_id', 'position', 'name', 'category', 'group_name', 'is_surcharge', 'price_cents',
        ]));
    }

    public function test_the_jmbg_is_never_part_of_an_offer(): void
    {
        foreach (self::TABLES as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertStringNotContainsString('jmbg', $column, "$table.$column");
            }
        }
    }

    public function test_the_only_foreign_keys_are_the_owner_and_the_parents_within_an_offer(): void
    {
        $this->assertSame(['users'], $this->foreignTables('offers'));
        $this->assertSame(['offers'], $this->foreignTables('offer_items'));
        $this->assertSame(['offer_items'], $this->foreignTables('offer_item_options'));
    }

    public function test_defaults_and_nullable_totals(): void
    {
        $offer = Offer::factory()->create();
        $item = OfferItem::factory()->create(['offer_id' => $offer->id]);
        $option = OfferItemOption::factory()->create(['offer_item_id' => $item->id]);

        $this->assertNull($offer->total_net_cents);
        $this->assertNull($offer->vat_cents);
        $this->assertNull($offer->total_gross_cents);
        $this->assertNull($item->line_net_cents);
        $this->assertSame(1, $item->quantity);
        $this->assertFalse($option->is_surcharge);
        $this->assertSame('RS', $offer->client_country);
    }

    public function test_amounts_are_integers_in_cents(): void
    {
        $offer = Offer::factory()->create(['total_net_cents' => 1_234_500, 'vat_cents' => 246_900, 'total_gross_cents' => 1_481_400, 'vat_rate_bp' => 2000]);
        $item = OfferItem::factory()->create(['offer_id' => $offer->id, 'version_price_cents' => 1_500_000, 'line_net_cents' => 3_000_000, 'quantity' => 2]);

        $fresh = $offer->fresh();
        $this->assertSame(1_481_400, $fresh->total_gross_cents);
        $this->assertSame(2000, $fresh->vat_rate_bp);
        $this->assertSame(3_000_000, $item->fresh()->line_net_cents);
        $this->assertIsInt($item->fresh()->version_price_cents);
    }

    // --- uniqueness of the number ---

    public function test_the_year_and_sequence_are_unique(): void
    {
        $offer = Offer::factory()->create(['year' => 2026, 'seq' => 7, 'number' => '007/2026']);

        $this->expectException(UniqueConstraintViolationException::class);
        Offer::factory()->create(['year' => 2026, 'seq' => 7, 'number' => '008/2026']);
        $this->assertNotNull($offer);
    }

    public function test_the_number_is_unique(): void
    {
        Offer::factory()->create(['year' => 2026, 'seq' => 7, 'number' => '007/2026']);

        $this->expectException(UniqueConstraintViolationException::class);
        Offer::factory()->create(['year' => 2026, 'seq' => 8, 'number' => '007/2026']);
    }

    public function test_the_same_sequence_may_repeat_in_another_year(): void
    {
        Offer::factory()->create(['year' => 2026, 'seq' => 1, 'number' => '001/2026']);
        Offer::factory()->create(['year' => 2027, 'seq' => 1, 'number' => '001/2027']);

        $this->assertSame(2, Offer::count());
    }

    public function test_the_number_and_the_owner_are_not_mass_assignable(): void
    {
        $fillable = (new Offer)->getFillable();

        foreach (['user_id', 'year', 'seq', 'number'] as $column) {
            $this->assertNotContains($column, $fillable);
        }
    }

    // --- relations ---

    public function test_relations_work_in_both_directions_in_order(): void
    {
        $client = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $client->id]);
        $second = OfferItem::factory()->create(['offer_id' => $offer->id, 'position' => 2, 'trim_name' => 'Style']);
        $first = OfferItem::factory()->create(['offer_id' => $offer->id, 'position' => 1]);
        $b = OfferItemOption::factory()->create(['offer_item_id' => $first->id, 'position' => 2, 'name' => 'B']);
        $a = OfferItemOption::factory()->create(['offer_item_id' => $first->id, 'position' => 1, 'name' => 'A']);

        $this->assertSame([$first->id, $second->id], $offer->items->pluck('id')->all());
        $this->assertSame([$a->id, $b->id], $first->options->pluck('id')->all());
        $this->assertSame($offer->id, $client->offers()->sole()->id);
        $this->assertSame($client->id, $offer->user->id);
        $this->assertSame($offer->id, $b->offerItem->offer->id);
    }

    public function test_the_parts_of_an_offer_go_with_the_offer(): void
    {
        $offer = Offer::factory()->create();
        $item = OfferItem::factory()->create(['offer_id' => $offer->id]);
        OfferItemOption::factory()->create(['offer_item_id' => $item->id]);

        $offer->forceDelete();

        $this->assertSame(0, OfferItem::count());
        $this->assertSame(0, OfferItemOption::count());
    }

    // --- rules of the models ---

    public function test_an_item_needs_a_vehicle_and_amounts_are_not_negative(): void
    {
        $offer = Offer::factory()->create();

        foreach ([['quantity' => 0], ['version_price_cents' => -1], ['line_net_cents' => -5]] as $bad) {
            try {
                OfferItem::factory()->create(['offer_id' => $offer->id] + $bad);
                $this->fail('Expected a rejected item for '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->assertSame(0, OfferItem::count());
            }
        }
    }

    public function test_the_price_of_an_option_is_not_negative_but_may_be_zero(): void
    {
        $item = OfferItem::factory()->create();

        OfferItemOption::factory()->create(['offer_item_id' => $item->id, 'price_cents' => 0]);
        $this->expectException(InvalidArgumentException::class);
        OfferItemOption::factory()->create(['offer_item_id' => $item->id, 'price_cents' => -1]);
    }

    public function test_a_surcharge_keeps_its_group_and_flag(): void
    {
        $option = OfferItemOption::factory()->surcharge('Točkovi')->create(['name' => 'Felne 17', 'price_cents' => 41_200]);

        $fresh = $option->fresh();
        $this->assertTrue($fresh->is_surcharge);
        $this->assertSame('Točkovi', $fresh->group_name);
        $this->assertSame(41_200, $fresh->price_cents);
    }

    // --- the catalog cannot change or block an offer ---

    public function test_the_catalog_can_be_changed_deleted_and_deactivated_without_touching_an_offer(): void
    {
        $version = Version::factory()->create(['base_price_cents' => 1_500_000]);
        $trim = $version->trim;
        $item = OfferItem::factory()->create([
            'car_model_name' => $trim->carModel->name,
            'trim_name' => $trim->name,
            'version_price_cents' => $version->base_price_cents,
        ]);
        $before = $item->fresh()->getAttributes();

        // The matrix deletes rows, prices change, things get deactivated and deleted.
        $row = TrimEquipment::factory()->create(['trim_id' => $trim->id]);
        $row->delete();
        $version->update(['base_price_cents' => 1_900_000, 'is_active' => false]);
        $trim->update(['name' => 'Preimenovana', 'is_active' => false]);
        $version->delete();
        $trim->delete();
        Engine::query()->delete();

        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame(1, Offer::count());
    }

    public function test_deleting_a_version_through_the_admin_is_not_blocked_by_offers(): void
    {
        $admin = User::factory()->admin()->create();
        $version = Version::factory()->create();
        OfferItem::factory()->create(['trim_name' => $version->trim->name]);

        $this->actingAs($admin)->delete("/admin/catalog/versions/{$version->id}")->assertSessionHas('success');

        $this->assertNull(Version::find($version->id));
        $this->assertSame(1, OfferItem::count());
    }

    public function test_the_purge_command_knows_every_offer_table(): void
    {
        $this->assertEqualsCanonicalizing(self::TABLES, config('catalog.offer_tables'));

        foreach (config('catalog.offer_tables') as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    // --- deleting a client ---

    public function test_a_client_with_offers_cannot_be_deleted_by_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();
        Offer::factory()->count(2)->create(['user_id' => $client->id]);

        $this->actingAs($admin)->delete('/admin/clients/'.$client->clientProfile->id)
            ->assertRedirect()
            ->assertSessionHas('error', 'Klijent ima ponude (2) i ne može da se obriše.');

        $this->assertNotNull(User::find($client->id));
        $this->assertNotNull($client->clientProfile()->first());
        $this->assertSame(2, Offer::count());
        $this->assertSame(0, ActivityLog::where('action', 'user.deleted')->count());
    }

    public function test_the_foreign_key_is_the_safety_net_for_the_owner(): void
    {
        $client = User::factory()->client()->create();
        Offer::factory()->create(['user_id' => $client->id]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $client->id)->delete();
    }

    public function test_a_client_without_offers_is_still_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();
        $profileId = $client->clientProfile->id;

        $this->actingAs($admin)->delete("/admin/clients/$profileId")->assertSessionHas('success');

        $this->assertNull(User::find($client->id));
    }

    // --- activity log ---

    public function test_offers_items_and_options_are_logged_with_a_readable_label(): void
    {
        $offer = Offer::factory()->create(['number' => '012/2026', 'year' => 2026, 'seq' => 12]);
        $item = OfferItem::factory()->create(['offer_id' => $offer->id]);
        OfferItemOption::factory()->create(['offer_item_id' => $item->id, 'name' => 'Climatronic']);

        $this->assertStringContainsString('012/2026', ActivityLog::where('action', 'offer.created')->sole()->subject_label);
        $this->assertStringContainsString('Fabia Essence', ActivityLog::where('action', 'offer_item.created')->sole()->subject_label);
        $this->assertStringContainsString('Climatronic', ActivityLog::where('action', 'offer_item_option.created')->sole()->subject_label);

        $offer->update(['note' => 'Hvala']);
        $this->assertSame(['old' => null, 'new' => 'Hvala'], ActivityLog::where('action', 'offer.updated')->sole()->changes['note']);
    }

    public function test_the_pib_of_the_client_is_logged_by_field_name_only(): void
    {
        Offer::factory()->create(['client_type' => 'company', 'client_pib' => '123456789']);

        $log = ActivityLog::where('action', 'offer.created')->sole();

        $this->assertSame(['redacted' => true], $log->changes['client_pib']);
        $this->assertStringNotContainsString('123456789', ActivityLog::all()->toJson());
    }

    public function test_every_action_and_field_of_the_offer_tables_is_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);
        $entities = ['offer' => 'offers', 'offer_item' => 'offer_items', 'offer_item_option' => 'offer_item_options'];

        foreach ($entities as $entity => $table) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $this->assertNotEmpty($translations["activity.action.$entity.$event"] ?? null, "$entity.$event");
            }

            foreach (array_diff(Schema::getColumnListing($table), ['id', 'created_at', 'updated_at']) as $column) {
                $label = $translations["activity.field.$entity.$column"] ?? $translations["activity.field.$column"] ?? null;
                $this->assertNotEmpty($label, "$table.$column");
            }
        }

        $this->assertNotEmpty($translations['Offer'] ?? null);
        $this->assertNotEmpty($translations['The client has offers (:count) and cannot be deleted.'] ?? null);
    }

    public function test_no_trim_is_needed_to_keep_an_offer(): void
    {
        $item = OfferItem::factory()->create();

        $this->assertSame(0, Trim::count());
        $this->assertSame(1, OfferItem::count());
        $this->assertNotNull($item->offer);
    }
}
