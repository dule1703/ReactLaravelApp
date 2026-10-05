<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\User;
use App\Services\OfferCreator;
use App\Support\ClientSnapshot;
use App\Support\OfferClientRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * DatabaseMigrations for the same reason as OfferNumberTest: the service must see a real
 * transaction, and the rollback test must not be hidden by a test-wide one.
 */
class OfferCreatorTest extends TestCase
{
    use DatabaseMigrations;

    private const JMBG = '0101990710006';

    private OfferCreator $creator;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', config('app.timezone')));
        $this->creator = app(OfferCreator::class);
        $this->client = $this->clientWithProfile(['jmbg' => self::JMBG]);
    }

    /** @param array<string, mixed> $profile */
    private function clientWithProfile(array $profile = []): User
    {
        $user = User::factory()->client()->create();
        $user->profile()->forceFill(array_merge([
            'full_name' => 'Petar Petrović',
            'address' => 'Knez Mihailova 1',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'country' => 'RS',
        ], $profile))->save();

        return $user->fresh();
    }

    public function test_the_vat_rate_is_a_snapshot(): void
    {
        $first = $this->creator->create($this->client);
        $this->assertSame(2000, $first->vat_rate_bp);

        Setting::setVatRateBp(2500);

        $this->assertSame(2000, $first->fresh()->vat_rate_bp);
        $this->assertSame(2500, $this->creator->create($this->client)->vat_rate_bp);
    }

    public function test_an_invalid_stored_rate_is_an_error_and_leaves_nothing_behind(): void
    {
        foreach (['abc', '-1', '10001', '20.5', ''] as $bad) {
            DB::table('settings')->where('key', 'vat_rate_bp')->update(['value' => $bad]);

            try {
                $this->creator->create($this->client);
                $this->fail("The value '$bad' should have been refused.");
            } catch (UnexpectedValueException) {
                // expected
            }
        }

        $this->assertSame(0, Offer::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_the_client_details_are_a_snapshot_without_the_jmbg(): void
    {
        $offer = $this->creator->create($this->client)->fresh();

        $this->assertSame($this->client->id, $offer->user_id);
        $this->assertSame('individual', $offer->client_type);
        $this->assertSame('Petar Petrović', $offer->client_name);
        $this->assertNull($offer->client_pib);
        $this->assertSame('Knez Mihailova 1', $offer->client_address);
        $this->assertSame('11000', $offer->client_postal_code);
        $this->assertSame('Beograd', $offer->client_city);
        $this->assertSame('RS', $offer->client_country);

        $this->client->profile()->update(['full_name' => 'Neko Drugi', 'city' => 'Niš']);

        $offer = $offer->fresh();
        $this->assertSame('Petar Petrović', $offer->client_name);
        $this->assertSame('Beograd', $offer->client_city);
    }

    public function test_the_jmbg_appears_nowhere(): void
    {
        $offer = $this->creator->create($this->client);

        $this->assertStringNotContainsString(self::JMBG, $offer->toJson());
        $this->assertStringNotContainsString(self::JMBG, json_encode(ClientSnapshot::from($this->client->profile())));
        $this->assertStringNotContainsString(self::JMBG, json_encode(DB::table('offers')->get()));
        $this->assertStringNotContainsString(self::JMBG, json_encode(ActivityLog::all()->toArray()));
        $this->assertStringNotContainsString('0101***', $offer->toJson());
    }

    public function test_the_pib_of_a_company_is_snapshotted_and_logged_only_as_a_field_name(): void
    {
        $company = $this->clientWithProfile(['type' => ClientType::Company, 'pib' => '123456789', 'jmbg' => null]);

        $offer = $this->creator->create($company);

        $this->assertSame('company', $offer->client_type);
        $this->assertSame('123456789', $offer->fresh()->client_pib);

        $log = ActivityLog::where('action', 'offer.created')->firstOrFail();
        $this->assertStringContainsString('client_pib', json_encode($log->changes));
        $this->assertStringNotContainsString('123456789', json_encode($log->toArray()));
    }

    public function test_an_individual_with_a_pib_keeps_it(): void
    {
        $user = $this->clientWithProfile(['pib' => '123456789']);

        $this->assertSame('123456789', $this->creator->create($user)->client_pib);
    }

    public function test_the_date_and_the_year_of_the_number_come_from_the_same_value(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', config('app.timezone')));

        $offer = $this->creator->create($this->client)->fresh();

        $this->assertSame('2026-12-31', $offer->offer_date->toDateString());
        $this->assertSame(2026, $offer->year);
        $this->assertSame('001/2026', $offer->number);

        // 00:30 on New Year's Day in Belgrade is still the previous year in UTC.
        $this->travelTo(Carbon::parse('2027-01-01 00:30:00', config('app.timezone')));

        $offer = $this->creator->create($this->client)->fresh();

        $this->assertSame('2027-01-01', $offer->offer_date->toDateString());
        $this->assertSame('001/2027', $offer->number);
    }

    public function test_a_rollback_does_not_spend_a_number(): void
    {
        // The number is already taken => the insert fails after the counter was incremented.
        Offer::factory()->create(['year' => 2026, 'seq' => 999, 'number' => '001/2026']);

        try {
            $this->creator->create($this->client);
            $this->fail('The duplicate number should have failed.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame(0, DB::table('offer_counters')->count());

        DB::table('offers')->where('number', '001/2026')->delete();

        $this->assertSame('001/2026', $this->creator->create($this->client)->number);
    }

    public function test_the_creation_is_logged_with_the_number(): void
    {
        $offer = $this->creator->create($this->client);

        $log = ActivityLog::where('action', 'offer.created')->firstOrFail();
        $this->assertSame($offer->id, $log->subject_id);
        $this->assertStringContainsString('001/2026', $log->subject_label);
    }

    public function test_the_note_is_trimmed_and_empty_becomes_null(): void
    {
        $this->assertSame('Za firmu', $this->creator->create($this->client, "  Za firmu \n")->fresh()->note);
        $this->assertNull($this->creator->create($this->client, '   ')->fresh()->note);
        $this->assertNull($this->creator->create($this->client)->fresh()->note);
    }

    public function test_only_a_client_can_own_an_offer(): void
    {
        try {
            $this->creator->create(User::factory()->admin()->create());
            $this->fail('An admin cannot own an offer.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Offer::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_an_incomplete_profile_is_refused_per_field_and_spends_no_number(): void
    {
        $empty = User::factory()->client()->create();

        try {
            $this->creator->create($empty);
            $this->fail('An empty profile should be refused.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['address', 'postal_code', 'city'], array_keys($e->errors()));
            $this->assertStringContainsString('adresa', $e->errors()['address'][0]);
        }

        $this->assertSame(0, Offer::count());
        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_the_minimum_of_the_profile_depends_on_the_type(): void
    {
        $company = $this->clientWithProfile(['type' => ClientType::Company, 'pib' => null, 'jmbg' => null]);

        $this->assertSame(['pib'], OfferClientRules::missing($company->profile()));
        $this->assertSame([], OfferClientRules::missing($this->client->profile()));

        $this->expectException(ValidationException::class);
        $this->creator->create($company);
    }
}
