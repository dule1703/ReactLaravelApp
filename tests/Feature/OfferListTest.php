<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\User;
use App\Support\OfferPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfferListTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->other = User::factory()->client()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function offer(User $owner, array $attributes = []): Offer
    {
        static $seq = 0;
        $seq++;

        return Offer::factory()->create(array_merge([
            'user_id' => $owner->id,
            'year' => 2026,
            'seq' => $seq,
            'number' => sprintf('%03d/2026', $seq),
        ], $attributes));
    }

    /** @return list<int> ids of the rows of the list */
    private function ids(User $as, string $query = ''): array
    {
        $ids = [];

        $this->actingAs($as)->get('/offers'.$query)->assertOk()->assertInertia(function (Assert $page) use (&$ids) {
            $ids = array_column($page->toArray()['props']['offers']['data'], 'id');
        });

        return $ids;
    }

    public function test_a_client_sees_only_their_own_offers_and_an_admin_sees_all(): void
    {
        $mine = $this->offer($this->client);
        $mineToo = $this->offer($this->client);
        $theirs = $this->offer($this->other);

        $this->assertEqualsCanonicalizing([$mine->id, $mineToo->id], $this->ids($this->client));
        $this->assertEqualsCanonicalizing([$mine->id, $mineToo->id, $theirs->id], $this->ids($this->admin));
    }

    public function test_a_guest_goes_to_login(): void
    {
        $this->get('/offers')->assertRedirect(route('login'));
    }

    public function test_the_rows_expose_exactly_the_listed_keys(): void
    {
        $this->offer($this->client);

        $row = fn (User $as) => $this->actingAs($as)->get('/offers')->viewData('page')['props']['offers']['data'][0];

        $common = ['id', 'number', 'offer_date', 'vat_rate_bp', 'total_net_cents', 'vat_cents', 'total_gross_cents', 'withdrawn_at', 'note', 'items_count'];

        $this->assertEqualsCanonicalizing($common, array_keys($row($this->client)));
        $this->assertEqualsCanonicalizing([...$common, 'client_name', 'deleted_at'], array_keys($row($this->admin)));
    }

    public function test_the_search_finds_the_number_including_the_slash_the_name_and_the_note(): void
    {
        $a = $this->offer($this->client, ['year' => 2026, 'seq' => 12, 'number' => '012/2026', 'client_name' => 'Petar Petrović', 'note' => 'Za firmu']);
        $b = $this->offer($this->client, ['year' => 2026, 'seq' => 112, 'number' => '112/2026', 'client_name' => 'Marko Marković', 'note' => null]);

        $this->assertSame([$a->id], $this->ids($this->client, '?q='.urlencode('012/2026')));
        $this->assertSame([$b->id, $a->id], $this->ids($this->client, '?q='.urlencode('2/2026')));
        $this->assertSame([$b->id], $this->ids($this->client, '?q=Marko'));
        $this->assertSame([$a->id], $this->ids($this->client, '?q='.urlencode('za FIRMU')));
        $this->assertSame([], $this->ids($this->client, '?q=nothing'));
    }

    public function test_a_client_cannot_find_someone_elses_offer_by_number_or_name(): void
    {
        $this->offer($this->client);
        $this->offer($this->other, ['year' => 2026, 'seq' => 77, 'number' => '077/2026', 'client_name' => 'Zoran Zoranović', 'note' => 'tajna napomena']);

        $this->assertSame([], $this->ids($this->client, '?q='.urlencode('077/2026')));
        $this->assertSame([], $this->ids($this->client, '?q='.urlencode('Zoranović')));
        $this->assertSame([], $this->ids($this->client, '?q='.urlencode('tajna')));
        $this->assertCount(1, $this->ids($this->admin, '?q='.urlencode('077/2026')));
    }

    public function test_the_pib_is_searchable_only_by_the_admin(): void
    {
        $offer = $this->offer($this->client, ['client_type' => 'company', 'client_pib' => '123456789']);

        $this->assertSame([$offer->id], $this->ids($this->admin, '?q=123456789'));
        $this->assertSame([], $this->ids($this->client, '?q=123456789'));
    }

    public function test_like_wildcards_in_the_search_are_taken_literally(): void
    {
        $percent = $this->offer($this->client, ['note' => '100% cotton']);
        $underscore = $this->offer($this->client, ['note' => 'a_b']);
        $this->offer($this->client, ['note' => 'axb']);
        $this->offer($this->client, ['note' => 'plain']);

        $this->assertSame([$percent->id], $this->ids($this->client, '?q='.urlencode('%')));
        $this->assertSame([$underscore->id], $this->ids($this->client, '?q='.urlencode('a_b')));
        $this->assertSame([$underscore->id], $this->ids($this->client, '?q='.urlencode('_')));
        $this->assertSame([], $this->ids($this->client, '?q='.urlencode('!')));
    }

    public function test_per_page_must_be_on_the_list_and_the_search_is_limited(): void
    {
        $this->actingAs($this->client)->get('/offers?per_page=7')->assertSessionHasErrors('per_page');
        $this->actingAs($this->client)->get('/offers?per_page=abc')->assertSessionHasErrors('per_page');
        $this->actingAs($this->client)->get('/offers?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
        $this->actingAs($this->client)->get('/offers?per_page=25')->assertOk();
    }

    public function test_the_default_order_is_the_newest_date_then_the_newest_id(): void
    {
        $old = $this->offer($this->client, ['offer_date' => '2026-01-10']);
        $newA = $this->offer($this->client, ['offer_date' => '2026-03-01']);
        $newB = $this->offer($this->client, ['offer_date' => '2026-03-01']);

        $this->assertSame([$newB->id, $newA->id, $old->id], $this->ids($this->client));
    }

    public function test_the_pagination_remembers_the_search_and_the_page_size(): void
    {
        foreach (range(1, 12) as $i) {
            $this->offer($this->client, ['note' => "match $i"]);
        }
        $this->offer($this->client, ['note' => 'other']);

        $this->actingAs($this->client)->get('/offers?q=match&per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where('offers.total', 12)
            ->has('offers.data', 10)
            ->where('offers.next_page_url', fn ($url) => str_contains($url, 'q=match') && str_contains($url, 'per_page=10'))
            ->where('filters', ['q' => 'match', 'status' => 'all', 'per_page' => 10])
            ->where('perPageOptions', [10, 25, 50])
            ->where('isAdmin', false));
    }

    public function test_the_empty_state_for_a_client_and_a_search_without_results(): void
    {
        $this->actingAs($this->client)->get('/offers')->assertInertia(fn (Assert $page) => $page
            ->component('Offers/Index')
            ->has('offers.data', 0)
            ->where('filters.q', ''));
    }

    public function test_an_offer_without_stored_totals_does_not_break_the_list(): void
    {
        $this->offer($this->client, ['total_net_cents' => null, 'vat_cents' => null, 'total_gross_cents' => null]);

        $this->actingAs($this->client)->get('/offers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('offers.data.0.total_net_cents', null)
            ->where('offers.data.0.vat_cents', null)
            ->where('offers.data.0.total_gross_cents', null));
    }

    public function test_the_amounts_are_the_stored_columns(): void
    {
        $this->offer($this->client, ['vat_rate_bp' => 2000, 'total_net_cents' => 5_540_000, 'vat_cents' => 1_108_000, 'total_gross_cents' => 6_648_000]);

        $this->actingAs($this->client)->get('/offers')->assertInertia(fn (Assert $page) => $page
            ->where('offers.data.0.vat_rate_bp', 2000)
            ->where('offers.data.0.total_net_cents', 5_540_000)
            ->where('offers.data.0.vat_cents', 1_108_000)
            ->where('offers.data.0.total_gross_cents', 6_648_000));
    }

    public function test_a_long_note_is_shortened_in_the_list(): void
    {
        $offer = $this->offer($this->client, ['note' => str_repeat('x', 500)]);
        $offer->items_count = 0;

        $this->assertLessThanOrEqual(123, mb_strlen(OfferPresenter::row($offer, false)['note']));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_offers(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->client)->get('/offers?per_page=50')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $first = $this->offer($this->client);
        OfferItem::factory()->count(2)->create(['offer_id' => $first->id]);
        $few = $count();

        foreach (range(1, 15) as $i) {
            $offer = $this->offer($this->client);
            OfferItem::factory()->count(3)->create(['offer_id' => $offer->id]);
        }

        $this->assertSame($few, $count());
    }

    public function test_the_items_count_is_the_number_of_items(): void
    {
        $offer = $this->offer($this->client);
        OfferItem::factory()->count(3)->create(['offer_id' => $offer->id]);

        $this->actingAs($this->client)->get('/offers')->assertInertia(fn (Assert $page) => $page->where('offers.data.0.items_count', 3));
    }

    public function test_the_configurator_route_still_works_and_bad_ids_are_404(): void
    {
        $this->actingAs($this->client)->get('/offers/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Offers/Create'));
        $this->actingAs($this->client)->get('/offers/abc')->assertNotFound();
        $this->actingAs($this->client)->get('/offers/0')->assertNotFound();
        $this->actingAs($this->client)->get('/offers/999999')->assertNotFound();
    }
}
