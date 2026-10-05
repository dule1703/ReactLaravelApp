<?php

namespace Tests\Feature;

use App\Models\CarModel;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

class OfferCatalogEndpointsTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile();
    }

    private function versions(CarModel $model)
    {
        return $this->actingAs($this->client)->getJson(route('offers.catalog.versions', $model));
    }

    private function detail(Version $version)
    {
        return $this->actingAs($this->client)->getJson(route('offers.catalog.version', $version));
    }

    public function test_the_versions_of_a_model_are_only_the_available_ones_with_net_prices(): void
    {
        $model = $this->version->trim->carModel;
        $inactive = Version::factory()->inactive()->create(['trim_id' => $this->version->trim_id]);
        $emptyTrim = Trim::factory()->create(['car_model_id' => $model->id, 'name' => 'Empty']);
        Version::factory()->create(['trim_id' => $emptyTrim->id, 'engine_id' => $this->version->engine_id, 'transmission_id' => $this->version->transmission_id, 'is_active' => false]);

        $response = $this->versions($model)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonCount(1, 'trims')
            ->assertJsonPath('trims.0.name', 'Style')
            ->assertJsonCount(1, 'trims.0.versions')
            ->assertJsonPath('trims.0.versions.0.id', $this->version->id)
            ->assertJsonPath('trims.0.versions.0.price_cents', 2_500_000)
            ->assertJsonPath('trims.0.versions.0.engine.fuel_type', $this->version->engine->fuel_type->value)
            ->assertJsonPath('trims.0.versions.0.transmission.drive', $this->version->transmission->drive->value);
        $this->assertNotContains($inactive->id, collect($response->json('trims.0.versions'))->pluck('id')->all());
    }

    public function test_a_hidden_model_is_404_and_a_version_of_a_hidden_model_is_not_offered(): void
    {
        $model = $this->version->trim->carModel;
        $model->update(['is_active' => false]);

        $this->versions($model)->assertNotFound();
        $this->detail($this->version)->assertNotFound();
    }

    public function test_a_version_detail_has_standard_extras_and_groups_with_the_default(): void
    {
        $response = $this->detail($this->version)->assertOk();

        $response->assertJsonPath('version.id', $this->version->id)
            ->assertJsonPath('version.price_cents', 2_500_000)
            ->assertJsonPath('version.model', 'Octavia')
            ->assertJsonPath('version.trim', 'Style');

        // Standard equipment (to show): the plain standard item and the default of the paint group.
        $this->assertEqualsCanonicalizing(['Alarm', 'Bela'], collect($response->json('standard'))->pluck('name')->all());

        $response->assertJsonCount(1, 'extras')
            ->assertJsonPath('extras.0.id', $this->climatronic->id)
            ->assertJsonPath('extras.0.price_cents', 150_000);

        $response->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.name', 'Boja karoserije')
            ->assertJsonPath('groups.0.selection', 'single')
            ->assertJsonPath('groups.0.uses_swatch', true)
            ->assertJsonPath('groups.0.default.id', $this->white->id)
            ->assertJsonPath('groups.0.default.swatch_hex', '#FFFFFF')
            ->assertJsonCount(1, 'groups.0.options')
            ->assertJsonPath('groups.0.options.0.id', $this->metallic->id)
            ->assertJsonPath('groups.0.options.0.price_cents', 120_000)
            ->assertJsonPath('groups.0.options.0.swatch_hex', '#8A8D8F');
    }

    public function test_a_multiple_group_has_no_default(): void
    {
        $group = OptionGroup::factory()->multiple()->create(['name' => 'Paketi']);
        $this->extra(10_000, $group, 'Paket A');

        $groups = collect($this->detail($this->version)->json('groups'))->keyBy('name');

        $this->assertNull($groups['Paketi']['default']);
        $this->assertSame('multiple', $groups['Paketi']['selection']);
    }

    public function test_inactive_items_and_groups_and_other_lines_are_not_in_the_detail(): void
    {
        $this->extra(1000, null, 'Off', ['is_active' => false]);
        $this->extra(1000, OptionGroup::factory()->inactive()->create(), 'In inactive group');
        $this->metallic->group->update(['is_active' => false]);

        $other = Version::factory()->create();
        TrimEquipment::factory()->optional(5000)->create([
            'trim_id' => $other->trim_id,
            'equipment_item_id' => EquipmentItem::factory()->create(['name' => 'Other line']),
        ]);

        $json = json_encode($this->detail($this->version)->json());

        $this->assertStringNotContainsString('"Off"', $json);
        $this->assertStringNotContainsString('In inactive group', $json);
        $this->assertStringNotContainsString('Other line', $json);
        $this->assertStringNotContainsString('Metalik', $json);
        $this->assertStringNotContainsString('Boja karoserije', $json);
        // The default of a deactivated group is not shown either.
        $this->assertStringNotContainsString('Bela', $json);
    }

    public function test_prices_come_from_the_catalog_never_from_the_request(): void
    {
        $response = $this->actingAs($this->client)
            ->getJson(route('offers.catalog.version', $this->version).'?price_cents=1&base_price_cents=1')
            ->assertOk();

        $this->assertSame(2_500_000, $response->json('version.price_cents'));
        $this->assertSame(150_000, $response->json('extras.0.price_cents'));
    }

    public function test_an_unavailable_inactive_or_unknown_version_is_404(): void
    {
        $this->detail(Version::factory()->inactive()->create())->assertNotFound();
        $this->actingAs($this->client)->getJson('/offers/catalog/versions/999999')->assertNotFound();
        $this->actingAs($this->client)->getJson('/offers/catalog/versions/abc')->assertNotFound();
    }

    public function test_only_a_client_can_read_the_endpoints(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('offers.catalog.version', $this->version))->assertForbidden();

        auth()->logout();
        $this->getJson(route('offers.catalog.version', $this->version))->assertUnauthorized();
        $this->getJson(route('offers.catalog.versions', $this->version->trim->carModel))->assertUnauthorized();
    }
}
