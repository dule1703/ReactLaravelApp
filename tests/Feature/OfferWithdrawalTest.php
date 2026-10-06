<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** 4.6b: the client withdraws their own offer (a status, not a deletion); only an admin undoes it. */
class OfferWithdrawalTest extends TestCase
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

    private function offer(array $attributes = []): Offer
    {
        return Offer::factory()->create(['user_id' => $this->client->id, ...$attributes]);
    }

    private function withdrawn(): Offer
    {
        $offer = $this->offer();
        $offer->forceFill(['withdrawn_at' => now()])->save();

        return $offer;
    }

    public function test_the_owner_withdraws_and_it_is_logged_once(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->client)->post(route('offers.withdraw', $offer))
            ->assertRedirect(route('offers.show', $offer))
            ->assertSessionHas('success');

        $this->assertNotNull($offer->fresh()->withdrawn_at);

        $entries = ActivityLog::query()->where('subject_type', $offer->getMorphClass())->where('subject_id', $offer->id)->where('action', '!=', 'offer.created')->get();
        $this->assertSame(['offer.withdrawn'], $entries->pluck('action')->all());
        $this->assertSame($this->client->id, $entries[0]->user_id);
        $this->assertSame(['withdrawn_at'], array_keys($entries[0]->changes));
        $this->assertStringNotContainsString($offer->client_name, json_encode($entries[0]->toArray()));
        $this->assertSame(0, ActivityLog::where('action', 'offer.updated')->count());
    }

    public function test_a_withdrawn_offer_stays_in_the_list_with_the_mark_and_opens(): void
    {
        $offer = $this->withdrawn();

        $row = $this->actingAs($this->client)->get('/offers')->assertOk()->viewData('page')['props']['offers']['data'][0];
        $this->assertSame($offer->withdrawn_at->format('d.m.Y'), $row['withdrawn_at']);

        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertOk();
        $this->actingAs($this->client)->get(route('offers.pdf', $offer))->assertOk();
    }

    public function test_the_pdf_of_a_withdrawn_offer_has_the_stamp_and_the_normal_one_does_not(): void
    {
        $withdrawn = $this->withdrawn();
        $normal = $this->offer();

        $this->assertStringContainsString('POVUČENA', app(OfferPdf::class)->html($withdrawn));
        $this->assertStringNotContainsString('POVUČENA', app(OfferPdf::class)->html($normal));
    }

    public function test_the_status_filter(): void
    {
        $normal = $this->offer();
        $withdrawn = $this->withdrawn();
        $ids = fn (string $query) => collect($this->actingAs($this->client)->get('/offers'.$query)->viewData('page')['props']['offers']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$normal->id, $withdrawn->id], $ids(''));
        $this->assertSame([$normal->id], $ids('?status=active'));
        $this->assertSame([$withdrawn->id], $ids('?status=withdrawn'));
        $this->actingAs($this->client)->get('/offers?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_someone_elses_offer_cannot_be_withdrawn(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->other)->post(route('offers.withdraw', $offer))->assertNotFound();
        $this->assertNull($offer->fresh()->withdrawn_at);
    }

    public function test_a_guest_goes_to_login(): void
    {
        $offer = $this->offer();

        $this->post(route('offers.withdraw', $offer))->assertRedirect(route('login'));
        $this->post(route('offers.withdrawal.revert', $offer))->assertRedirect(route('login'));
    }

    public function test_an_admin_does_not_withdraw(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->admin)->post(route('offers.withdraw', $offer))->assertForbidden();
        $this->assertNull($offer->fresh()->withdrawn_at);
    }

    public function test_withdrawing_twice_changes_nothing_and_writes_no_second_entry(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->client)->post(route('offers.withdraw', $offer));
        $first = $offer->fresh()->withdrawn_at;
        $this->travel(5)->minutes();

        $this->actingAs($this->client)->post(route('offers.withdraw', $offer))->assertRedirect()->assertSessionHas('success');

        $this->assertEquals($first, $offer->fresh()->withdrawn_at);
        $this->assertSame(1, ActivityLog::where('action', 'offer.withdrawn')->count());
    }

    public function test_an_admin_undoes_the_withdrawal_and_it_is_logged(): void
    {
        $offer = $this->withdrawn();

        $this->actingAs($this->admin)->post(route('offers.withdrawal.revert', $offer))->assertRedirect(route('offers.show', $offer));

        $this->assertNull($offer->fresh()->withdrawn_at);
        $this->assertSame(1, ActivityLog::where('action', 'offer.withdrawal_reverted')->where('user_id', $this->admin->id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'offer.updated')->count());

        // Not withdrawn any more: repeating is not an error and writes nothing.
        $this->actingAs($this->admin)->post(route('offers.withdrawal.revert', $offer))->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'offer.withdrawal_reverted')->count());
    }

    public function test_a_client_does_not_undo_a_withdrawal(): void
    {
        $offer = $this->withdrawn();

        $this->actingAs($this->client)->post(route('offers.withdrawal.revert', $offer))->assertForbidden();
        $this->actingAs($this->other)->post(route('offers.withdrawal.revert', $offer))->assertNotFound();
        $this->assertNotNull($offer->fresh()->withdrawn_at);
    }

    public function test_the_status_cannot_be_mass_assigned(): void
    {
        $offer = $this->offer();
        $offer->fill(['withdrawn_at' => now()])->save();

        $this->assertNull($offer->fresh()->withdrawn_at);
    }

    public function test_the_policy_answers(): void
    {
        $offer = $this->offer();

        $this->assertTrue(Gate::forUser($this->client)->allows('withdraw', $offer));
        $this->assertSame(404, Gate::forUser($this->other)->inspect('withdraw', $offer)->status());
        $this->assertSame(403, Gate::forUser($this->admin)->inspect('withdraw', $offer)->status());
        $this->assertTrue(Gate::forUser($this->admin)->allows('revertWithdrawal', $offer));
        $this->assertSame(404, Gate::forUser($this->other)->inspect('revertWithdrawal', $offer)->status());
    }
}
