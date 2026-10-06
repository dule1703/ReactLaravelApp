<?php

namespace Tests\Feature;

use App\Http\Requests\StoreOfferRequest;
use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\User;
use App\Services\OfferCreator;
use App\Services\OfferNotEditableException;
use App\Services\OfferNoteUpdater;
use App\Services\OfferPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/** 4.6c: the only thing of an offer that can be edited is the note. */
class OfferNoteEditTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private User $client;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile();
        $this->other = $this->clientWithProfile();
        $this->admin = User::factory()->admin()->create();
    }

    private function offer(): Offer
    {
        return app(OfferCreator::class)->create($this->client, 'Stara napomena', [[
            'version_id' => $this->version->id, 'quantity' => 2, 'option_ids' => [$this->climatronic->id, $this->metallic->id],
        ]]);
    }

    private function withdraw(Offer $offer): void
    {
        $offer->forceFill(['withdrawn_at' => now()])->save();
    }

    /** @return array<string, mixed> everything of the offer except the note and updated_at */
    private function snapshot(Offer $offer): array
    {
        return [
            'offer' => collect($offer->fresh()->getAttributes())->except(['note', 'updated_at'])->all(),
            'items' => OfferItem::query()->where('offer_id', $offer->id)->get()->map->getAttributes()->all(),
            'options' => OfferItemOption::query()->whereIn('offer_item_id', OfferItem::where('offer_id', $offer->id)->pluck('id'))->get()->map->getAttributes()->all(),
        ];
    }

    /** @return list<ActivityLog> */
    private function noteEntries(Offer $offer): array
    {
        return ActivityLog::query()->where('action', 'offer.note_updated')->where('subject_id', $offer->id)->get()->all();
    }

    public function test_the_owner_and_an_admin_change_only_the_note(): void
    {
        $offer = $this->offer();
        $before = $this->snapshot($offer);

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer), ['note' => '  Nova napomena  '])
            ->assertRedirect(route('offers.show', $offer))
            ->assertSessionHas('success');
        $this->assertSame('Nova napomena', $offer->fresh()->note);

        $this->actingAs($this->admin)->patch(route('offers.note.update', $offer), ['note' => 'Admin je promenio'])
            ->assertRedirect(route('offers.show', $offer));
        $this->assertSame('Admin je promenio', $offer->fresh()->note);

        $this->assertSame($before, $this->snapshot($offer));
    }

    public function test_an_empty_note_is_stored_as_null(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer), ['note' => '   '])->assertRedirect();
        $this->assertNull($offer->fresh()->note);
    }

    public function test_someone_elses_offer_is_not_found_and_a_guest_goes_to_login(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->other)->patch(route('offers.note.update', $offer), ['note' => 'Tuđe'])->assertNotFound();
        // Not found comes before validation: a 422 would reveal that the number exists.
        $this->actingAs($this->other)->patch(route('offers.note.update', $offer), ['note' => str_repeat('a', 5000)])->assertNotFound();
        $this->assertSame('Stara napomena', $offer->fresh()->note);

        auth()->logout();
        $this->patch(route('offers.note.update', $offer), ['note' => 'Gost'])->assertRedirect(route('login'));
    }

    public function test_a_withdrawn_offer_is_forbidden_for_the_owner_and_the_admin(): void
    {
        $offer = $this->offer();
        $this->withdraw($offer);

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer), ['note' => 'Pokušaj'])->assertForbidden();
        $this->actingAs($this->admin)->patch(route('offers.note.update', $offer), ['note' => 'Pokušaj'])->assertForbidden();
        $this->actingAs($this->other)->patch(route('offers.note.update', $offer), ['note' => 'Pokušaj'])->assertNotFound();

        $this->assertSame('Stara napomena', $offer->fresh()->note);
        $this->assertSame([], $this->noteEntries($offer));
    }

    public function test_a_deleted_offer_is_not_found_for_everyone(): void
    {
        $offer = $this->offer();
        $offer->delete();

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer->id), ['note' => 'x'])->assertNotFound();
        $this->actingAs($this->admin)->patch(route('offers.note.update', $offer->id), ['note' => 'x'])->assertNotFound();
        $this->assertSame('Stara napomena', Offer::withTrashed()->find($offer->id)->note);
    }

    public function test_invalid_input_is_422_and_nothing_is_written(): void
    {
        $offer = $this->offer();
        $url = route('offers.note.update', $offer);

        $this->actingAs($this->client)->patch($url, ['note' => str_repeat('a', StoreOfferRequest::NOTE_MAX + 1)])->assertSessionHasErrors('note');
        $this->actingAs($this->client)->patch($url, ['note' => ['niz']])->assertSessionHasErrors('note');
        $this->actingAs($this->client)->patch($url, [])->assertSessionHasErrors('note');
        $this->actingAs($this->client)->patch($url, ['note' => str_repeat('a', StoreOfferRequest::NOTE_MAX)])->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('a', StoreOfferRequest::NOTE_MAX), $offer->fresh()->note);
    }

    public function test_the_same_value_changes_nothing_and_writes_no_entry(): void
    {
        $offer = $this->offer();
        $updatedAt = $offer->fresh()->updated_at;
        $this->travel(1)->hours();

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer), ['note' => ' Stara napomena '])
            ->assertRedirect()
            ->assertSessionHas('success', __('The note of offer :number is unchanged.', ['number' => $offer->number]));

        $this->assertEquals($updatedAt, $offer->fresh()->updated_at);
        $this->assertSame([], $this->noteEntries($offer));
    }

    public function test_the_service_rechecks_the_status_under_the_lock(): void
    {
        $offer = $this->offer();
        $stale = Offer::find($offer->id); // loaded before the withdrawal, like a request that lost the race
        $this->withdraw($offer);

        $this->assertFalse($stale->isWithdrawn());
        $this->expectException(OfferNotEditableException::class);

        try {
            app(OfferNoteUpdater::class)->update($stale, 'Kasno');
        } finally {
            $this->assertSame('Stara napomena', $offer->fresh()->note);
        }
    }

    public function test_the_log_has_one_entry_with_the_field_name_and_never_the_text(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->admin)->patch(route('offers.note.update', $offer), ['note' => 'Tajna napomena JMBG 123'])->assertRedirect();

        $entries = $this->noteEntries($offer);
        $this->assertCount(1, $entries);
        $this->assertSame($this->admin->id, $entries[0]->user_id);
        $this->assertSame(['note' => ['redacted' => true]], $entries[0]->changes);
        $this->assertSame(0, ActivityLog::where('action', 'offer.updated')->where('subject_id', $offer->id)->count());

        $all = json_encode(ActivityLog::all()->toArray());
        $this->assertStringNotContainsString('Tajna napomena', $all);
        $this->assertStringNotContainsString('Stara napomena', $all);
    }

    public function test_the_creation_entry_does_not_hold_the_note_either(): void
    {
        $offer = $this->offer();

        $created = ActivityLog::where('action', 'offer.created')->where('subject_id', $offer->id)->firstOrFail();

        $this->assertSame(['redacted' => true], $created->changes['note']);
        $this->assertStringNotContainsString('Stara napomena', json_encode($created->toArray()));
    }

    public function test_the_action_has_a_translated_name(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/sr_Latn.json')), true);

        $this->assertNotEmpty($translations['activity.action.offer.note_updated'] ?? null);
    }

    public function test_the_new_pdf_has_the_new_note(): void
    {
        $offer = $this->offer();
        $this->assertStringContainsString('Stara napomena', app(OfferPdf::class)->html($offer->fresh()));

        $this->actingAs($this->client)->patch(route('offers.note.update', $offer), ['note' => 'Sveža napomena'])->assertRedirect();

        $html = app(OfferPdf::class)->html($offer->fresh());
        $this->assertStringContainsString('Sveža napomena', $html);
        $this->assertStringNotContainsString('Stara napomena', $html);
        $this->actingAs($this->client)->get(route('offers.pdf', $offer))->assertOk();
    }

    public function test_the_page_gets_the_flag_and_the_limit_as_separate_props_and_the_presenter_has_no_new_key(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertInertia(fn (Assert $page) => $page
            ->where('canEditNote', true)
            ->where('noteMax', StoreOfferRequest::NOTE_MAX)
            ->missing('offer.can_edit_note'));
        $this->actingAs($this->admin)->get(route('offers.show', $offer))->assertInertia(fn (Assert $page) => $page->where('canEditNote', true));

        $this->withdraw($offer);
        $this->actingAs($this->client)->get(route('offers.show', $offer))->assertInertia(fn (Assert $page) => $page->where('canEditNote', false));
        $this->actingAs($this->admin)->get(route('offers.show', $offer))->assertInertia(fn (Assert $page) => $page->where('canEditNote', false));
    }
}
