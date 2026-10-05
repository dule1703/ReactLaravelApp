<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

class OfferStoreTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile();
    }

    /** (2,500,000 + 150,000 + 120,000) x 2 = 5,540,000 net, 20% VAT 1,108,000. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'items' => [['version_id' => $this->version->id, 'quantity' => 2, 'option_ids' => [$this->climatronic->id, $this->metallic->id]]],
            'note' => 'Za firmu',
            'expected_total_net_cents' => 5_540_000,
            'expected_total_gross_cents' => 6_648_000,
        ], $override);
    }

    private function save(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->client)->postJson(route('offers.store'), $payload);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, OfferItem::count());
        $this->assertSame(0, OfferItemOption::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_a_client_saves_an_offer_and_the_server_writes_its_own_amounts(): void
    {
        $response = $this->save($this->payload());

        $response->assertCreated()->assertJson(['number' => now()->format('Y') === '2026' ? '001/2026' : '001/'.now()->format('Y'), 'redirect' => route('offers.create')]);
        $response->assertSessionHas('success');

        $offer = Offer::sole();
        $this->assertSame($this->client->id, $offer->user_id);
        $this->assertSame(5_540_000, $offer->total_net_cents);
        $this->assertSame(1_108_000, $offer->vat_cents);
        $this->assertSame(6_648_000, $offer->total_gross_cents);
        $this->assertSame('Za firmu', $offer->note);
        $this->assertSame(1, OfferItem::count());
        $this->assertSame(2, OfferItemOption::count());
    }

    public function test_the_owner_is_always_the_signed_in_client_and_client_id_is_ignored(): void
    {
        $other = $this->clientWithProfile();

        $this->save($this->payload(['client_id' => $other->id, 'user_id' => $other->id]))->assertCreated();

        $this->assertSame($this->client->id, Offer::sole()->user_id);
    }

    public function test_an_invalid_choice_is_422_with_the_key_of_the_item_and_nothing_is_written(): void
    {
        $response = $this->save($this->payload(['items' => [['version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => [999_999]]]]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['items.0.option_ids.0']);
        $this->assertNothingWritten();
    }

    public function test_different_totals_are_409_with_the_server_amounts_and_nothing_is_written(): void
    {
        $response = $this->save($this->payload(['expected_total_net_cents' => 5_000_000]));

        $response->assertStatus(409)
            ->assertJson([
                'totals' => ['total_net_cents' => 5_540_000, 'vat_cents' => 1_108_000, 'total_gross_cents' => 6_648_000],
                'vat_rate_bp' => 2000,
            ])
            ->assertJsonStructure(['message'])
            ->assertHeaderMissing('X-Inertia-Location');
        $this->assertNothingWritten();
    }

    public function test_a_changed_vat_rate_is_a_409_with_the_new_rate(): void
    {
        Setting::setVatRateBp(2500);

        $this->save($this->payload())->assertStatus(409)->assertJson(['vat_rate_bp' => 2500]);
        $this->assertNothingWritten();
    }

    public function test_confirming_the_new_amounts_saves_the_offer(): void
    {
        $this->version->update(['base_price_cents' => 2_600_000]);

        $first = $this->save($this->payload());
        $first->assertStatus(409);
        $totals = $first->json('totals');

        $this->save($this->payload([
            'expected_total_net_cents' => $totals['total_net_cents'],
            'expected_total_gross_cents' => $totals['total_gross_cents'],
        ]))->assertCreated();

        $this->assertSame($totals['total_net_cents'], Offer::sole()->total_net_cents);
    }

    public function test_the_expected_net_total_is_a_required_whole_number(): void
    {
        $this->assertExpectedIsRequiredWholeNumber('expected_total_net_cents');
    }

    public function test_the_expected_gross_total_is_a_required_whole_number(): void
    {
        $this->assertExpectedIsRequiredWholeNumber('expected_total_gross_cents');
    }

    /** One field per test: six requests stay under the throttle of ten a minute. */
    private function assertExpectedIsRequiredWholeNumber(string $field): void
    {
        foreach ([null, '5540000', 1.5, -1, 'x'] as $bad) {
            $this->save($this->payload([$field => $bad]))->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $payload = $this->payload();
        unset($payload[$field]);
        $this->save($payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertNothingWritten();
    }

    public function test_items_are_required_and_limited(): void
    {
        $item = $this->payload()['items'][0];

        $this->save($this->payload(['items' => []]))->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->save($this->payload(['items' => array_fill(0, 21, $item)]))->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->save(array_diff_key($this->payload(), ['items' => 1]))->assertUnprocessable()->assertJsonValidationErrors(['items']);
        $this->save($this->payload(['note' => str_repeat('a', 1001)]))->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->assertNothingWritten();
    }

    public function test_a_wrong_type_inside_an_item_is_422_not_a_server_error(): void
    {
        $this->save($this->payload(['items' => [['version_id' => 'x', 'quantity' => [], 'option_ids' => 'y']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.version_id', 'items.0.quantity', 'items.0.option_ids']);
        $this->save($this->payload(['items' => ['x']]))->assertUnprocessable();
        $this->assertNothingWritten();
    }

    public function test_an_admin_and_a_guest_are_refused(): void
    {
        $this->save($this->payload(), User::factory()->admin()->create())->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('offers.create'))->assertForbidden();

        auth()->logout();
        $this->postJson(route('offers.store'), $this->payload())->assertUnauthorized();
        $this->get(route('offers.create'))->assertRedirect(route('login'));
        $this->assertNothingWritten();
    }

    public function test_an_incomplete_profile_is_refused_per_field_and_the_page_names_the_missing_fields(): void
    {
        $empty = User::factory()->client()->create();

        $this->save($this->payload(), $empty)->assertUnprocessable()->assertJsonValidationErrors(['address', 'postal_code', 'city']);
        $this->assertNothingWritten();

        $this->actingAs($empty)->get(route('offers.create'))->assertInertia(fn (Assert $page) => $page
            ->component('Offers/Create')
            ->where('profileMissing', ['address', 'postal_code', 'city']));
    }

    public function test_the_page_has_models_with_an_available_version_the_rate_and_the_limits_only(): void
    {
        $hidden = CarModel::factory()->create(['name' => 'Hidden']);
        $hiddenTrim = Trim::factory()->create(['car_model_id' => $hidden->id]);
        Version::factory()->inactive()->create(['trim_id' => $hiddenTrim->id]);
        CarModel::factory()->create(['name' => 'Empty']);

        $this->actingAs($this->client)->get(route('offers.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Offers/Create')
            ->has('models', 1)
            ->where('models.0.name', 'Octavia')
            ->where('vatRateBp', 2000)
            ->where('profileMissing', [])
            ->where('limits.maxItems', 20)
            ->where('limits.maxQuantity', 999)
            ->where('limits.noteMax', 1000));
    }

    public function test_saving_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->save($this->payload(['items' => []]))->assertUnprocessable();
        }

        $this->save($this->payload(['items' => []]))->assertStatus(429);
    }

    public function test_the_creation_is_logged_once_for_the_items(): void
    {
        $this->save($this->payload())->assertCreated();

        $this->assertSame(1, ActivityLog::where('action', 'offer.items_created')->count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['offer_item.created', 'offer_item_option.created'])->count());
    }

    public function test_the_navigation_of_a_client_has_new_offer_and_a_disabled_offers_item(): void
    {
        $this->actingAs($this->client)->get(route('client-profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('key', 'new-offer')['href'] === route('offers.create', absolute: false)
                && collect($nav)->firstWhere('key', 'offers')['soon'] === true));
    }
}
