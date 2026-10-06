<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OfferPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_any_is_for_both_roles(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->client()->create())->allows('viewAny', Offer::class));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('viewAny', Offer::class));
    }

    public function test_an_admin_views_any_offer_and_a_client_only_their_own(): void
    {
        $owner = User::factory()->client()->create();
        $other = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('view', $offer));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $offer));
        $this->assertFalse(Gate::forUser($other)->allows('view', $offer));
    }

    public function test_someone_elses_offer_is_denied_as_not_found(): void
    {
        $offer = Offer::factory()->create();
        $other = User::factory()->client()->create();

        $response = Gate::forUser($other)->inspect('view', $offer);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_a_client_and_an_admin_create_and_a_guest_does_not(): void
    {
        // Changed on purpose in 4.5d: an admin creates an offer on behalf of a client.
        $this->assertTrue(Gate::forUser(User::factory()->client()->create())->allows('create', Offer::class));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('create', Offer::class));
        $this->assertFalse(Gate::forUser(null)->allows('create', Offer::class));
    }

    public function test_only_an_admin_chooses_a_client(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('chooseClient', Offer::class));
        $this->assertFalse(Gate::forUser(User::factory()->client()->create())->allows('chooseClient', Offer::class));
        $this->assertFalse(Gate::forUser(null)->allows('chooseClient', Offer::class));
    }
}
