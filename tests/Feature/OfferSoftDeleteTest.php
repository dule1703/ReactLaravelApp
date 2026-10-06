<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** 4.6b: an admin deletes (soft) and restores offers; a deleted offer is "not found" for everyone. */
class OfferSoftDeleteTest extends TestCase
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

    private function ids(User $as, string $query = ''): array
    {
        return collect($this->actingAs($as)->get('/offers'.$query)->assertOk()->viewData('page')['props']['offers']['data'])->pluck('id')->all();
    }

    public function test_an_admin_deletes_and_the_offer_is_gone_for_everyone(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer))->assertRedirect(route('offers.index'))->assertSessionHas('success');

        $this->assertSoftDeleted($offer);
        $this->assertSame(1, DB::table('offers')->count(), 'the row stays');

        foreach ([$this->admin, $this->client] as $user) {
            $this->actingAs($user)->get(route('offers.show', $offer))->assertNotFound();
            $this->actingAs($user)->get(route('offers.pdf', $offer))->assertNotFound();
            $this->assertSame([], $this->ids($user));
            $this->assertSame([], $this->ids($user, '?q='.urlencode($offer->number)));
        }
    }

    public function test_deleting_is_logged_once_without_client_data_and_the_pdf_of_a_deleted_offer_logs_nothing(): void
    {
        $offer = $this->offer(['client_name' => 'Tajni Klijent', 'client_pib' => '123456789']);
        ActivityLog::query()->delete();

        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer));
        $this->actingAs($this->client)->get(route('offers.pdf', $offer))->assertNotFound();

        $entries = ActivityLog::all();
        $this->assertSame(['offer.deleted'], $entries->pluck('action')->all());
        $this->assertSame($this->admin->id, $entries[0]->user_id);
        $this->assertSame($offer->id, $entries[0]->subject_id);
        $this->assertNull($entries[0]->changes);
        $this->assertStringNotContainsString('Tajni', $entries->toJson());
        $this->assertStringNotContainsString('123456789', $entries->toJson());
    }

    public function test_deleting_twice_is_a_404_and_writes_no_second_entry(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer));
        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer))->assertNotFound();

        $this->assertSame(1, ActivityLog::where('action', 'offer.deleted')->count());
    }

    public function test_an_admin_restores_with_the_withdrawal_kept_and_it_is_logged(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['withdrawn_at' => now()])->save();
        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer));
        ActivityLog::query()->delete();

        $this->actingAs($this->admin)->post(route('offers.restore', $offer))->assertRedirect(route('offers.index', ['status' => 'deleted']));

        $fresh = Offer::find($offer->id);
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->withdrawn_at);
        $this->assertSame(['offer.restored'], ActivityLog::pluck('action')->all(), 'one entry, no offer.updated');
        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertOk();

        // Not deleted any more: idempotent, no second entry.
        $this->actingAs($this->admin)->post(route('offers.restore', $offer))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, ActivityLog::where('action', 'offer.restored')->count());
    }

    public function test_a_withdrawn_offer_can_be_deleted(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['withdrawn_at' => now()])->save();

        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer))->assertRedirect();

        $this->assertSoftDeleted($offer);
    }

    public function test_a_client_can_neither_delete_nor_restore(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->client)->delete(route('offers.destroy', $offer))->assertForbidden();
        $this->actingAs($this->other)->delete(route('offers.destroy', $offer))->assertNotFound();
        $this->assertNotSoftDeleted($offer);

        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer));

        $this->actingAs($this->client)->post(route('offers.restore', $offer))->assertNotFound();
        $this->actingAs($this->other)->post(route('offers.restore', $offer))->assertNotFound();
        $this->assertSoftDeleted($offer);
    }

    public function test_a_guest_goes_to_login(): void
    {
        $offer = $this->offer();

        $this->delete(route('offers.destroy', $offer))->assertRedirect(route('login'));
        $this->post(route('offers.restore', $offer))->assertRedirect(route('login'));
    }

    public function test_the_deleted_filter_is_for_the_admin_and_ignored_for_a_client(): void
    {
        $live = $this->offer();
        $deleted = $this->offer();
        $this->actingAs($this->admin)->delete(route('offers.destroy', $deleted));

        $this->assertSame([$live->id], $this->ids($this->admin));
        $this->assertSame([$deleted->id], $this->ids($this->admin, '?status=deleted'));
        // A client never sees a deleted offer: the value is ignored, not an error.
        $this->assertSame([$live->id], $this->ids($this->client, '?status=deleted'));
        $this->assertSame([], $this->ids($this->other, '?status=deleted'));

        $row = $this->actingAs($this->admin)->get('/offers?status=deleted')->viewData('page')['props']['offers']['data'][0];
        $this->assertSame(now()->format('d.m.Y'), $row['deleted_at']);
        $this->assertSame($deleted->client_name, $row['client_name']);
    }

    public function test_the_items_count_and_the_search_work_with_the_default_scope(): void
    {
        $live = $this->offer(['note' => 'zajednicka']);
        $deleted = $this->offer(['note' => 'zajednicka']);
        $this->actingAs($this->admin)->delete(route('offers.destroy', $deleted));

        $this->assertSame([$live->id], $this->ids($this->admin, '?q=zajednicka'));
        $this->assertSame([$deleted->id], $this->ids($this->admin, '?q=zajednicka&status=deleted'));
        $this->assertSame([], $this->ids($this->admin, '?q=zajednicka&status=withdrawn'));
    }

    public function test_deleted_offers_still_block_deleting_the_client(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->admin)->delete(route('offers.destroy', $offer));
        $profile = $this->client->profile();

        $this->assertSame(0, $this->client->offers()->count(), 'the default relation hides deleted offers');
        $this->actingAs($this->admin)->delete(route('clients.destroy', $profile))->assertSessionHas('error');

        $this->assertNotNull(User::find($this->client->id));
    }

    public function test_the_policy_answers(): void
    {
        $offer = $this->offer();

        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $offer));
        $this->assertSame(403, Gate::forUser($this->client)->inspect('delete', $offer)->status());
        $this->assertSame(404, Gate::forUser($this->other)->inspect('delete', $offer)->status());
        $this->assertTrue(Gate::forUser($this->admin)->allows('restore', $offer));
        $this->assertSame(404, Gate::forUser($this->client)->inspect('restore', $offer)->status());
    }
}
