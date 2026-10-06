<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 4.7: the whole OfferPolicy as one table: every ability x every actor x every state of the offer.
 * It fails when a rule moves, even if the single-ability tests still pass. The outcome is
 * "allow", a status (403 / 404) or "deny" (a guest: denied without a status, the route redirects
 * to the login page before the Policy is even asked).
 *
 * The Policy does not know about deletion: a deleted offer never reaches it through a route
 * (route binding answers 404, see test_a_deleted_offer_is_not_found_on_every_route), so the
 * "deleted" rows hold a trashed model and show that the rule itself does not depend on it.
 */
class OfferPolicyMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ACTORS = ['guest', 'owner', 'other', 'admin'];

    /** ability => actor => outcome; an array is per state (normal / withdrawn / deleted). */
    private const TABLE = [
        'viewAny' => ['guest' => 'deny', 'owner' => 'allow', 'other' => 'allow', 'admin' => 'allow'],
        'view' => ['guest' => 'deny', 'owner' => 'allow', 'other' => 404, 'admin' => 'allow'],
        'create' => ['guest' => 'deny', 'owner' => 'allow', 'other' => 'allow', 'admin' => 'allow'],
        'chooseClient' => ['guest' => 'deny', 'owner' => 'deny', 'other' => 'deny', 'admin' => 'allow'],
        'update' => [
            'guest' => 'deny',
            'owner' => ['normal' => 'allow', 'withdrawn' => 403, 'deleted' => 'allow'],
            'other' => 404,
            'admin' => ['normal' => 'allow', 'withdrawn' => 403, 'deleted' => 'allow'],
        ],
        'withdraw' => ['guest' => 'deny', 'owner' => 'allow', 'other' => 404, 'admin' => 403],
        'revertWithdrawal' => ['guest' => 'deny', 'owner' => 403, 'other' => 404, 'admin' => 'allow'],
        'delete' => ['guest' => 'deny', 'owner' => 403, 'other' => 404, 'admin' => 'allow'],
        'restore' => ['guest' => 'deny', 'owner' => 404, 'other' => 404, 'admin' => 'allow'],
    ];

    /** Abilities without an offer (the class is passed instead). */
    private const CLASS_LEVEL = ['viewAny', 'create', 'chooseClient'];

    /** @return array<string, array{string, string, string, bool|int|string}> */
    public static function matrix(): array
    {
        $rows = [];

        foreach (self::TABLE as $ability => $byActor) {
            foreach (self::ACTORS as $actor) {
                foreach (['normal', 'withdrawn', 'deleted'] as $state) {
                    $expected = $byActor[$actor];
                    $expected = is_array($expected) ? $expected[$state] : $expected;
                    $rows["$ability / $actor / $state"] = [$ability, $actor, $state, $expected];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('matrix')]
    public function test_the_policy_matrix(string $ability, string $actor, string $state, int|string $expected): void
    {
        $owner = User::factory()->client()->create();
        $users = [
            'guest' => null,
            'owner' => $owner,
            'other' => User::factory()->client()->create(),
            'admin' => User::factory()->admin()->create(),
        ];
        $offer = Offer::factory()->create(['user_id' => $owner->id]);

        if ($state === 'withdrawn') {
            $offer->forceFill(['withdrawn_at' => now()])->save();
        }

        if ($state === 'deleted') {
            $offer->delete();
        }

        $argument = in_array($ability, self::CLASS_LEVEL, true) ? Offer::class : $offer;
        $response = Gate::forUser($users[$actor])->inspect($ability, $argument);

        if ($expected === 'allow') {
            $this->assertTrue($response->allowed(), "$ability / $actor / $state should be allowed");

            return;
        }

        $this->assertTrue($response->denied(), "$ability / $actor / $state should be denied");
        $this->assertSame($expected === 'deny' ? null : $expected, $response->status(), "$ability / $actor / $state status");
    }

    public function test_a_deleted_offer_is_not_found_on_every_route(): void
    {
        $owner = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $owner->id]);
        $offer->delete();

        $routes = [
            ['get', 'offers.show'],
            ['get', 'offers.pdf'],
            ['patch', 'offers.note.update'],
            ['post', 'offers.withdraw'],
            ['post', 'offers.withdrawal.revert'],
            ['delete', 'offers.destroy'],
        ];

        foreach ([$owner, User::factory()->client()->create(), User::factory()->admin()->create()] as $user) {
            foreach ($routes as [$method, $name]) {
                $this->actingAs($user)->call($method, route($name, $offer->id), $method === 'patch' ? ['note' => 'x'] : [])
                    ->assertNotFound("$name as {$user->role->value}");
            }
        }

        // The one route that reaches a deleted offer: restore, for the admin only.
        $this->actingAs($owner)->post(route('offers.restore', $offer->id))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->post(route('offers.restore', $offer->id))->assertRedirect();
        $this->assertNull(Offer::find($offer->id)->deleted_at);
    }
}
