<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\User;
use App\Services\OfferCreator;
use App\Support\OfferCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

class OfferShowTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private const JMBG = '0101990710006';

    private User $client;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile(['jmbg' => self::JMBG]);
        $this->other = $this->clientWithProfile();
        $this->admin = User::factory()->admin()->create();
    }

    /** @return array<string, mixed> the props of the page */
    private function props(User $as, Offer $offer): array
    {
        return $this->actingAs($as)->get(route('offers.show', $offer))->assertOk()->viewData('page')['props'];
    }

    private function realOffer(): Offer
    {
        return app(OfferCreator::class)->create($this->client, 'Za firmu', [[
            'version_id' => $this->version->id, 'quantity' => 2, 'option_ids' => [$this->climatronic->id, $this->metallic->id],
        ]]);
    }

    public function test_the_owner_and_an_admin_can_open_it_and_a_guest_goes_to_login(): void
    {
        $offer = $this->realOffer();

        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Offers/Show'));
        $this->actingAs($this->admin)->get(route('offers.show', $offer))->assertOk();

        auth()->logout();
        $this->get(route('offers.show', $offer))->assertRedirect(route('login'));
    }

    public function test_someone_elses_offer_is_not_found_not_forbidden(): void
    {
        $offer = $this->realOffer();

        $this->actingAs($this->other)->get(route('offers.show', $offer))->assertNotFound();
        $this->actingAs($this->other)->get('/offers/999999')->assertNotFound();
    }

    public function test_bad_ids_are_404_for_both_roles(): void
    {
        foreach ([$this->client, $this->admin] as $user) {
            $this->actingAs($user)->get('/offers/abc')->assertNotFound();
            $this->actingAs($user)->get('/offers/0')->assertNotFound();
            $this->actingAs($user)->get('/offers/-1')->assertNotFound();
            $this->actingAs($user)->get('/offers/999999')->assertNotFound();
        }
    }

    public function test_the_detail_exposes_exactly_the_listed_keys(): void
    {
        $offer = $this->realOffer();

        $common = ['id', 'number', 'offer_date', 'vat_rate_bp', 'note', 'total_net_cents', 'vat_cents', 'total_gross_cents', 'withdrawn_at', 'client', 'items'];

        $clientView = $this->props($this->client, $offer)['offer'];
        $adminView = $this->props($this->admin, $offer)['offer'];

        $this->assertEqualsCanonicalizing($common, array_keys($clientView));
        $this->assertEqualsCanonicalizing([...$common, 'client_profile_id'], array_keys($adminView));
        $this->assertEqualsCanonicalizing(['type', 'name', 'pib', 'address', 'postal_code', 'city', 'country'], array_keys($clientView['client']));
        $this->assertEqualsCanonicalizing(
            ['id', 'quantity', 'car_model_name', 'trim_name', 'engine_name', 'fuel_type', 'power_kw', 'transmission_name', 'drive', 'version_price_cents', 'line_net_cents', 'options'],
            array_keys($clientView['items'][0]),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'category', 'group_name', 'is_surcharge', 'price_cents'],
            array_keys($clientView['items'][0]['options'][0]),
        );
        $this->assertSame($this->client->profile()->id, $adminView['client_profile_id']);
    }

    public function test_the_client_data_is_the_snapshot_not_the_live_profile(): void
    {
        $offer = $this->realOffer();

        $this->client->profile()->update(['full_name' => 'Neko Drugi', 'city' => 'Niš', 'address' => 'Nova 5']);

        $client = $this->props($this->client, $offer)['offer']['client'];

        $this->assertSame('Petar Petrović', $client['name']);
        $this->assertSame('Beograd', $client['city']);
        $this->assertSame('Knez Mihailova 1', $client['address']);
    }

    public function test_the_jmbg_and_the_hash_appear_nowhere_on_the_pages(): void
    {
        $offer = $this->realOffer();
        $hash = $this->client->profile()->jmbg_hash;

        $this->assertNotNull($hash);

        // The page HTML also carries the route table (Ziggy), which has route names with "jmbg"; the
        // value and the hash must be absent from the whole page, the word from the props of the page.
        foreach ([$this->client, $this->admin] as $user) {
            foreach ([route('offers.show', $offer), route('offers.index')] as $url) {
                $response = $this->actingAs($user)->get($url);

                $this->assertStringNotContainsString(self::JMBG, $response->getContent());
                $this->assertStringNotContainsString($hash, $response->getContent());
                $this->assertStringNotContainsStringIgnoringCase('jmbg', json_encode($response->viewData('page')['props']));
            }
        }
    }

    public function test_the_amounts_are_the_stored_columns_and_equal_the_calculator_over_the_saved_items(): void
    {
        $offer = $this->realOffer()->fresh();
        $view = $this->props($this->client, $offer)['offer'];

        $this->assertSame($offer->total_net_cents, $view['total_net_cents']);
        $this->assertSame($offer->vat_cents, $view['vat_cents']);
        $this->assertSame($offer->total_gross_cents, $view['total_gross_cents']);
        $this->assertSame($offer->vat_rate_bp, $view['vat_rate_bp']);

        // Proof that the stored columns are what the calculator gives for the saved snapshot.
        $input = $offer->items->map(fn (OfferItem $item) => [
            'version_price_cents' => $item->version_price_cents,
            'quantity' => $item->quantity,
            'options' => $item->options->map(fn (OfferItemOption $option) => ['price_cents' => $option->price_cents])->all(),
        ])->all();
        $calculated = OfferCalculator::calculate($input, $offer->vat_rate_bp);

        $this->assertSame($calculated['total_net_cents'], $view['total_net_cents']);
        $this->assertSame($calculated['vat_cents'], $view['vat_cents']);
        $this->assertSame($calculated['total_gross_cents'], $view['total_gross_cents']);
        $this->assertSame(array_column($calculated['items'], 'line_net_cents'), array_column($view['items'], 'line_net_cents'));
    }

    public function test_the_items_and_options_show_the_snapshot_with_the_surcharge_marked(): void
    {
        $offer = $this->realOffer();

        $item = $this->props($this->client, $offer)['offer']['items'][0];

        $this->assertSame('Octavia', $item['car_model_name']);
        $this->assertSame('Style', $item['trim_name']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame(2_500_000, $item['version_price_cents']);
        $this->assertSame(5_540_000, $item['line_net_cents']);

        $options = collect($item['options'])->keyBy('name');
        $this->assertTrue($options['Metalik']['is_surcharge']);
        $this->assertSame('Boja karoserije', $options['Metalik']['group_name']);
        $this->assertSame(120_000, $options['Metalik']['price_cents']);
        $this->assertFalse($options['Climatronic']['is_surcharge']);
    }

    public function test_the_snapshot_does_not_follow_the_catalog(): void
    {
        $offer = $this->realOffer();
        $before = $this->props($this->client, $offer)['offer'];

        $this->version->update(['base_price_cents' => 9_900_000]);
        $this->version->trim->carModel->update(['name' => 'Renamed']);
        $this->climatronic->update(['name' => 'Renamed item', 'is_active' => false]);

        $this->assertSame($before, $this->props($this->client, $offer)['offer']);
    }

    public function test_an_offer_without_stored_totals_and_with_an_unknown_fuel_does_not_break_the_page(): void
    {
        $offer = Offer::factory()->create(['user_id' => $this->client->id, 'total_net_cents' => null, 'vat_cents' => null, 'total_gross_cents' => null]);
        OfferItem::factory()->create(['offer_id' => $offer->id, 'fuel_type' => 'hydrogen', 'drive' => 'tracked', 'line_net_cents' => null]);

        $view = $this->props($this->client, $offer)['offer'];

        $this->assertNull($view['total_net_cents']);
        $this->assertNull($view['items'][0]['line_net_cents']);
        $this->assertSame('hydrogen', $view['items'][0]['fuel_type']);
    }

    public function test_only_the_admin_gets_the_link_to_the_client(): void
    {
        $offer = $this->realOffer();

        $this->assertArrayNotHasKey('client_profile_id', $this->props($this->client, $offer)['offer']);
        $this->assertArrayHasKey('client_profile_id', $this->props($this->admin, $offer)['offer']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_items(): void
    {
        $count = function (Offer $offer) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get(route('offers.show', $offer))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = Offer::factory()->create(['user_id' => $this->client->id]);
        OfferItem::factory()->create(['offer_id' => $small->id]);

        $big = Offer::factory()->create(['user_id' => $this->client->id]);
        OfferItem::factory()->count(5)->create(['offer_id' => $big->id])
            ->each(fn (OfferItem $item) => OfferItemOption::factory()->count(3)->create(['offer_item_id' => $item->id]));

        $this->assertSame($count($small), $count($big));
    }
}
