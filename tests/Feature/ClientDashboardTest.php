<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use App\Support\ClientDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attributes = []): User
    {
        $client = User::factory()->client()->create($attributes);
        $client->profile()->update([
            'full_name' => 'Pera Peric', 'address' => 'Ulica 1', 'postal_code' => '11000',
            'city' => 'Beograd', 'country' => 'RS',
        ]);

        return $client;
    }

    /** @return array<string, mixed> */
    private function props(User $user): array
    {
        return $this->actingAs($user)->get('/dashboard')->assertOk()->viewData('page')['props'];
    }

    public function test_the_props_are_exactly_the_whitelist(): void
    {
        $client = $this->client(['name' => 'Pera']);
        Offer::factory()->create(['user_id' => $client->id]);

        $props = $this->props($client);

        $this->assertSame(['name', 'profile_missing', 'recent_offers'], array_values(array_intersect(array_keys($props), ['name', 'profile_missing', 'recent_offers'])));
        $this->assertSame(
            ['id', 'number', 'offer_date', 'total_gross_cents', 'withdrawn_at'],
            array_keys($props['recent_offers'][0]),
        );
    }

    public function test_the_page_is_for_the_client_and_greets_by_account_name(): void
    {
        $client = $this->client(['name' => 'Account Name']);
        $client->profile()->update(['full_name' => '']);

        $this->actingAs($client)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('name', 'Account Name')
            ->where('recent_offers', []));
    }

    public function test_a_client_sees_only_own_offers_and_never_deleted_ones(): void
    {
        $a = $this->client();
        $b = $this->client();
        $own = Offer::factory()->create(['user_id' => $a->id]);
        Offer::factory()->create(['user_id' => $b->id]);
        Offer::factory()->create(['user_id' => $a->id])->delete();

        $props = $this->props($a);

        $this->assertSame([$own->id], array_column($props['recent_offers'], 'id'));
    }

    public function test_it_lists_at_most_five_newest_offers_and_marks_withdrawn(): void
    {
        $client = $this->client();
        Offer::factory()->count(7)->create(['user_id' => $client->id]);
        $withdrawn = Offer::factory()->create(['user_id' => $client->id, 'withdrawn_at' => now(), 'offer_date' => now()->addDay()]);

        $offers = $this->props($client)['recent_offers'];

        $this->assertCount(ClientDashboard::RECENT_OFFERS, $offers);
        $this->assertSame($withdrawn->id, $offers[0]['id']);
        $this->assertNotNull($offers[0]['withdrawn_at']);
    }

    public function test_no_sensitive_data_reaches_the_props(): void
    {
        $client = $this->client();
        $client->profile()->update(['jmbg' => '0101990710006', 'pib' => '123456789']);
        Offer::factory()->create(['user_id' => $client->id]);

        $json = json_encode($this->props($client));

        $this->assertStringNotContainsString('0101990710006', $json);
        $this->assertStringNotContainsString('123456789', $json);
        $this->assertStringNotContainsString('Ulica 1', $json);
    }

    public function test_an_incomplete_profile_is_reported_by_field_names_only(): void
    {
        $client = User::factory()->client()->create();

        $this->assertSame(['address', 'postal_code', 'city'], $this->props($client)['profile_missing']);
    }

    public function test_a_complete_profile_reports_nothing_missing(): void
    {
        $this->assertSame([], $this->props($this->client())['profile_missing']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_offers(): void
    {
        $client = $this->client();

        $count = function () use ($client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ClientDashboard::data($client);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count();
        Offer::factory()->count(12)->create(['user_id' => $client->id]);
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(2, $many); // the profile (firstOrCreate) and the offers
    }

    public function test_an_admin_is_redirected_to_the_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
