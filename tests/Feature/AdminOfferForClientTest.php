<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/**
 * 4.5d: an admin makes an offer on behalf of a client they choose. The owner is the client, never the
 * admin; the client's own flow is unchanged (a client_id from a client is ignored).
 */
class AdminOfferForClientTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private const JMBG = '0101990710008';

    private User $admin;

    private User $client;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
        $this->client = $this->clientWithProfile(['jmbg' => self::JMBG, 'pib' => '123456789']);
        $this->other = $this->clientWithProfile(['full_name' => 'Marko Marković']);
    }

    /** (2,500,000 + 150,000 + 120,000) x 2 = 5,540,000 net, 20% VAT 1,108,000. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'client_id' => $this->client->profile()->id,
            'items' => [['version_id' => $this->version->id, 'quantity' => 2, 'option_ids' => [$this->climatronic->id, $this->metallic->id]]],
            'note' => 'Za klijenta',
            'expected_total_net_cents' => 5_540_000,
            'expected_total_gross_cents' => 6_648_000,
        ], $override);
    }

    private function save(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(route('offers.store'), $this->payload($override));
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Offer::count());
        $this->assertSame(0, OfferItem::count());
        $this->assertSame(0, OfferItemOption::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    private function search(string $q, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->getJson(route('offers.catalog.clients', ['q' => $q]));
    }

    private function clientNamed(string $name, array $profile = [], array $user = []): User
    {
        return $this->clientWithProfile(array_merge(['full_name' => $name], $profile), $user);
    }

    // --- saving ---------------------------------------------------------------------------

    public function test_an_admin_makes_an_offer_for_the_chosen_client_who_owns_it(): void
    {
        $response = $this->save()->assertCreated();

        $offer = Offer::sole();
        $this->assertSame($this->client->id, $offer->user_id);
        $this->assertNotSame($this->admin->id, $offer->user_id);
        $this->assertSame('Petar Petrović', $offer->client_name);
        $this->assertSame('Knez Mihailova 1', $offer->client_address);
        $this->assertSame('123456789', $offer->client_pib);
        $this->assertSame(5_540_000, $offer->total_net_cents);
        $this->assertSame('001/'.now()->format('Y'), $offer->number);
        $response->assertJson(['redirect' => route('offers.show', $offer)]);
        $response->assertSessionHas('success');
    }

    public function test_the_client_sees_it_in_their_list_and_another_client_does_not(): void
    {
        $this->save()->assertCreated();
        $offer = Offer::sole();

        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertOk();
        $ids = $this->actingAs($this->client)->get('/offers')->viewData('page')['props']['offers']['data'];
        $this->assertSame([$offer->id], array_column($ids, 'id'));

        $this->actingAs($this->other)->get(route('offers.show', $offer))->assertNotFound();
        $this->assertSame([], $this->actingAs($this->other)->get('/offers')->viewData('page')['props']['offers']['data']);
    }

    public function test_the_numbers_follow_the_order_whoever_makes_the_offer(): void
    {
        $this->save()->assertCreated();
        $this->save(['client_id' => $this->other->profile()->id])->assertCreated();
        $this->actingAs($this->client)->postJson(route('offers.store'), $this->payload())->assertCreated();

        $this->assertSame(['001', '002', '003'], Offer::orderBy('id')->get()->map(fn ($o) => substr($o->number, 0, 3))->all());
        $this->assertSame([$this->client->id, $this->other->id, $this->client->id], Offer::orderBy('id')->pluck('user_id')->all());
    }

    public function test_an_admin_without_a_client_or_with_a_bad_one_is_refused_and_nothing_is_written(): void
    {
        $withoutClient = $this->payload();
        unset($withoutClient['client_id']);
        $this->actingAs($this->admin)->postJson(route('offers.store'), $withoutClient)->assertUnprocessable()->assertJsonValidationErrors('client_id');

        foreach ([999_999, 0, -1, 'abc', '5', 1.5, null, []] as $bad) {
            $this->save(['client_id' => $bad])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        }

        $this->assertNothingWritten();
    }

    public function test_the_account_must_be_a_client(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $adminProfile = ClientProfile::factory()->for($otherAdmin)->create(['jmbg' => null]);

        $response = $this->save(['client_id' => $adminProfile->id])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->assertStringContainsString('postojećeg klijenta', $response->json('errors.client_id.0'));
        $this->assertNothingWritten();
    }

    public function test_a_client_who_sends_a_client_id_still_owns_the_offer(): void
    {
        $this->actingAs($this->client)->postJson(route('offers.store'), $this->payload(['client_id' => $this->other->profile()->id, 'user_id' => $this->other->id]))->assertCreated();

        $offer = Offer::sole();
        $this->assertSame($this->client->id, $offer->user_id);
        $this->assertSame('Petar Petrović', $offer->client_name);
    }

    public function test_a_client_who_sends_a_bad_client_id_is_not_refused_for_it(): void
    {
        $this->actingAs($this->client)->postJson(route('offers.store'), $this->payload(['client_id' => 'nonsense']))->assertCreated();

        $this->assertSame($this->client->id, Offer::sole()->user_id);
    }

    public function test_an_incomplete_profile_is_a_422_that_names_the_fields_and_writes_nothing(): void
    {
        $empty = User::factory()->client()->create();

        $response = $this->save(['client_id' => $empty->profile()->id])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $message = $response->json('errors.client_id.0');
        foreach (['Adresa', 'Poštanski broj', 'Grad'] as $field) {
            $this->assertStringContainsString($field, $message);
        }

        $this->assertNothingWritten();
    }

    public function test_a_company_without_a_pib_is_incomplete(): void
    {
        $company = $this->clientWithProfile(['type' => 'company', 'pib' => null]);

        $response = $this->save(['client_id' => $company->profile()->id])->assertUnprocessable();

        $this->assertStringContainsString('PIB', $response->json('errors.client_id.0'));
        $this->assertNothingWritten();
    }

    public function test_a_client_without_a_jmbg_can_get_an_offer_from_an_admin(): void
    {
        $this->save(['client_id' => $this->other->profile()->id])->assertCreated();

        $this->assertNull($this->other->profile()->jmbg);
        $this->assertSame($this->other->id, Offer::sole()->user_id);
    }

    public function test_the_totals_check_still_answers_409_for_an_admin(): void
    {
        $this->save(['expected_total_net_cents' => 5_000_000])->assertStatus(409)
            ->assertJson(['totals' => ['total_net_cents' => 5_540_000, 'vat_cents' => 1_108_000, 'total_gross_cents' => 6_648_000]])
            ->assertHeaderMissing('X-Inertia-Location');
        $this->assertNothingWritten();

        $this->save()->assertCreated();
    }

    public function test_the_log_has_the_admin_as_actor_and_no_client_data(): void
    {
        $this->save()->assertCreated();
        $offer = Offer::sole();

        $summary = ActivityLog::where('action', 'offer.items_created')->sole();
        $this->assertSame($this->admin->id, $summary->user_id);
        $this->assertSame('admin', $summary->user_role);
        $this->assertSame($offer->id, $summary->subject_id);

        $created = ActivityLog::where('action', 'offer.created')->where('subject_id', $offer->id)->sole();
        $this->assertSame($this->admin->id, $created->user_id);

        $json = json_encode($summary->toArray());
        foreach (['Petar Petrović', 'Knez Mihailova', 'Beograd', self::JMBG, '123456789'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
        $this->assertStringNotContainsString(self::JMBG, json_encode($created->toArray()));
        $this->assertStringNotContainsString('123456789', json_encode($created->toArray()));
    }

    // --- the page --------------------------------------------------------------------------

    public function test_the_page_for_an_admin_and_for_a_client(): void
    {
        $this->actingAs($this->admin)->get(route('offers.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Offers/Create')->where('isAdmin', true)->where('profileMissing', []));

        $empty = User::factory()->client()->create();
        $this->actingAs($empty)->get(route('offers.create'))->assertInertia(fn (Assert $page) => $page
            ->where('isAdmin', false)->where('profileMissing', ['address', 'postal_code', 'city']));
    }

    // --- the search of clients -------------------------------------------------------------

    public function test_only_an_admin_searches_clients(): void
    {
        $this->actingAs($this->client)->getJson(route('offers.catalog.clients', ['q' => 'Petar']))->assertForbidden();

        auth()->logout();
        $this->getJson(route('offers.catalog.clients', ['q' => 'Petar']))->assertUnauthorized();
        $this->get(route('offers.catalog.clients', ['q' => 'Petar']))->assertRedirect(route('login'));

        $this->search('Petar')->assertOk();
    }

    public function test_the_search_returns_exactly_the_listed_keys_and_no_jmbg_pib_or_address(): void
    {
        $response = $this->search('Petar')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $rows = $response->json('clients');
        $this->assertCount(1, $rows);
        $this->assertEqualsCanonicalizing(['id', 'name', 'city', 'email', 'complete'], array_keys($rows[0]));
        $this->assertSame($this->client->profile()->id, $rows[0]['id']);
        $this->assertSame('Petar Petrović', $rows[0]['name']);
        $this->assertSame('Beograd', $rows[0]['city']);
        $this->assertSame($this->client->email, $rows[0]['email']);
        $this->assertTrue($rows[0]['complete']);

        $content = $response->getContent();
        foreach ([self::JMBG, '123456789', 'Knez Mihailova', '11000', 'jmbg', 'pib'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $content);
        }
    }

    public function test_the_search_finds_a_company_by_the_name_in_the_profile(): void
    {
        $this->clientNamed('Firma Šumadija d.o.o.', ['type' => 'company', 'pib' => '987654321']);

        $rows = $this->search('umadij')->assertOk()->json('clients');

        $this->assertCount(1, $rows);
        $this->assertSame('Firma Šumadija d.o.o.', $rows[0]['name']);
    }

    public function test_it_finds_by_email_and_two_clients_with_the_same_name_and_city_differ_by_email(): void
    {
        $a = $this->clientNamed('Jovan Jovanović', ['city' => 'Niš'], ['email' => 'jovan.a@example.com']);
        $b = $this->clientNamed('Jovan Jovanović', ['city' => 'Niš'], ['email' => 'jovan.b@example.com']);

        $rows = $this->search('Jovan Jov')->assertOk()->json('clients');
        $this->assertSame(['jovan.a@example.com', 'jovan.b@example.com'], array_column($rows, 'email'));
        $this->assertSame([$a->profile()->id, $b->profile()->id], array_column($rows, 'id'));

        $byEmail = $this->search('jovan.b@')->assertOk()->json('clients');
        $this->assertSame([$b->profile()->id], array_column($byEmail, 'id'));
    }

    public function test_complete_is_false_for_an_incomplete_profile(): void
    {
        User::factory()->client()->create(['email' => 'prazan@example.com']);
        $rows = $this->search('prazan@')->assertOk()->json('clients');

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['complete']);
        $this->assertArrayNotHasKey('missing', $rows[0]);
    }

    public function test_the_search_needs_two_characters_and_has_a_limit_of_ten(): void
    {
        foreach (range(1, 12) as $i) {
            $this->clientNamed("Klijent Serija $i");
        }

        $this->search('a')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->search(' a ')->assertUnprocessable();
        $this->search(str_repeat('x', 101))->assertUnprocessable();
        $this->actingAs($this->admin)->getJson(route('offers.catalog.clients'))->assertUnprocessable();

        $this->assertCount(10, $this->search('Serija')->assertOk()->json('clients'));
    }

    public function test_like_wildcards_in_the_search_are_taken_literally(): void
    {
        $percent = $this->clientNamed('Promo 100% klijent');
        $underscore = $this->clientNamed('Ab_cd klijent');
        $this->clientNamed('Abxcd klijent');
        $this->clientNamed('Prosti klijent');

        $this->assertSame([$percent->profile()->id], array_column($this->search('0%')->json('clients'), 'id'));
        $this->assertSame([$underscore->profile()->id], array_column($this->search('b_')->json('clients'), 'id'));
        $this->assertSame([], $this->search('!!')->json('clients'));
    }

    public function test_only_accounts_with_the_role_client_are_found(): void
    {
        $otherAdmin = User::factory()->admin()->create(['email' => 'sef@example.com']);
        ClientProfile::factory()->for($otherAdmin)->create(['full_name' => 'Admin Profil', 'jmbg' => null]);

        $this->assertSame([], $this->search('Admin Profil')->json('clients'));
        $this->assertSame([], $this->search('sef@')->json('clients'));
    }

    // --- every action authorizes -----------------------------------------------------------

    public function test_the_actions_of_the_routes_refuse_a_guest_and_a_client_where_they_must(): void
    {
        $versionUrl = route('offers.catalog.version', $this->version);
        $versionsUrl = route('offers.catalog.versions', $this->version->trim->carModel);

        // A guest: signed-in users only.
        foreach ([route('offers.create'), $versionUrl, $versionsUrl, route('offers.catalog.clients', ['q' => 'ab'])] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->postJson(route('offers.store'), $this->payload())->assertUnauthorized();

        // A client: the configurator and the catalog yes, the client search no.
        $this->actingAs($this->client)->get(route('offers.create'))->assertOk();
        $this->actingAs($this->client)->getJson($versionUrl)->assertOk();
        $this->actingAs($this->client)->getJson($versionsUrl)->assertOk();
        $this->actingAs($this->client)->getJson(route('offers.catalog.clients', ['q' => 'ab']))->assertForbidden();

        // An admin: all of it.
        $this->actingAs($this->admin)->get(route('offers.create'))->assertOk();
        $this->actingAs($this->admin)->getJson($versionUrl)->assertOk();
        $this->actingAs($this->admin)->getJson($versionsUrl)->assertOk();
        $this->actingAs($this->admin)->getJson(route('offers.catalog.clients', ['q' => 'ab']))->assertOk();

        $this->assertNothingWritten();
    }

    public function test_the_admin_client_routes_still_refuse_a_client_and_a_guest(): void
    {
        foreach ([route('clients.index'), route('clients.create')] as $url) {
            $this->actingAs($this->client)->get($url)->assertForbidden();
        }
        $this->actingAs($this->client)->post(route('clients.store'), [])->assertForbidden();

        auth()->logout();
        $this->get(route('clients.index'))->assertRedirect(route('login'));
        $this->get(route('clients.create'))->assertRedirect(route('login'));
    }

    public function test_the_search_is_throttled_and_the_new_route_is_before_the_offer_parameter(): void
    {
        $this->assertContains('throttle:catalog-read', Route::getRoutes()->getByName('offers.catalog.clients')->gatherMiddleware());

        $this->actingAs($this->admin)->get('/offers/catalog/clients?q=ab')->assertOk();
        $this->actingAs($this->admin)->get('/offers/new')->assertOk();
    }
}
