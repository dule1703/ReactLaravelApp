<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\OfferCounter;
use App\Models\User;
use App\Services\OfferNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * DatabaseMigrations (not RefreshDatabase) on purpose: RefreshDatabase wraps every test in a
 * transaction, which would hide the "no transaction" guard of the service.
 *
 * What these tests prove: the numbering logic, the reset per year, that a rollback leaves no
 * gap, the guards and the backfill. What they cannot prove: two simultaneous requests. SQLite
 * serializes writers and has no row locks, so the exclusive lock of the counter row (MySQL) is
 * not exercised here; it follows from the atomic UPDATE and is the reason for it (see the
 * comment of the service). The unique keys of `offers` stay the safety net.
 */
class OfferNumberTest extends TestCase
{
    use DatabaseMigrations;

    private OfferNumber $numbers;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 12:00:00');
        $this->numbers = new OfferNumber;
        $this->client = User::factory()->client()->create();
    }

    /** The way phase 4.3 will create an offer: new, assign, save, in one transaction with a retry. */
    private function create(int $year = 2026): Offer
    {
        return DB::transaction(function () use ($year) {
            $offer = (new Offer)->fill([
                'offer_date' => "$year-03-01",
                'vat_rate_bp' => 2000,
            ]);
            $offer->user_id = $this->client->id;
            $this->numbers->assign($offer, $year);
            $offer->save();

            return $offer;
        }, 3);
    }

    public function test_the_format_is_three_digits_slash_four_digit_year(): void
    {
        $this->assertSame('012/2026', OfferNumber::format(12, 2026));
        $this->assertSame('001/2026', OfferNumber::format(1, 2026));
        $this->assertSame('999/2026', OfferNumber::format(999, 2026));
    }

    public function test_the_first_offer_of_a_year_is_number_one_and_the_row_of_the_year_is_created(): void
    {
        $this->assertSame(0, DB::table('offer_counters')->count());

        $offer = $this->create();

        $this->assertSame('001/2026', $offer->number);
        $this->assertSame(1, $offer->seq);
        $this->assertSame(2026, $offer->year);
        $this->assertSame(1, (int) DB::table('offer_counters')->where('year', 2026)->value('last_seq'));
        $this->assertSame('001/2026', $offer->fresh()->number);
    }

    public function test_numbers_are_consecutive(): void
    {
        $numbers = [$this->create()->number, $this->create()->number, $this->create()->number];

        $this->assertSame(['001/2026', '002/2026', '003/2026'], $numbers);
    }

    public function test_a_deleted_offer_keeps_its_number_and_the_next_one_gets_the_next_number(): void
    {
        $first = $this->create();
        $first->delete();

        $this->assertSame('002/2026', $this->create()->number);
        $this->assertSame('001/2026', Offer::withTrashed()->find($first->id)->number);
    }

    public function test_the_sequence_restarts_every_year_and_the_old_year_continues(): void
    {
        $this->create();
        $this->create();

        $this->travelTo('2027-01-02 09:00:00');

        $this->assertSame('001/2027', $this->create(2027)->number);
        // An offer dated in the old year (e.g. entered on 2 January) continues the old sequence.
        $this->assertSame('003/2026', $this->create(2026)->number);
        $this->assertSame('002/2027', $this->create(2027)->number);
    }

    public function test_a_rollback_does_not_use_up_a_number(): void
    {
        $this->create();

        try {
            DB::transaction(function () {
                $offer = (new Offer)->fill(['offer_date' => '2026-03-01', 'vat_rate_bp' => 2000]);
                $offer->user_id = $this->client->id;
                $this->numbers->assign($offer, 2026);
                $offer->save();

                throw new RuntimeException('the rest of the offer failed');
            });
            $this->fail('The transaction should have failed.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, Offer::count());
        $this->assertSame(1, (int) DB::table('offer_counters')->where('year', 2026)->value('last_seq'));
        $this->assertSame('002/2026', $this->create()->number);
    }

    public function test_a_rollback_of_the_first_offer_of_a_year_leaves_no_counter_row(): void
    {
        try {
            DB::transaction(function () {
                $this->numbers->assign(new Offer, 2026);

                throw new RuntimeException('failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DB::table('offer_counters')->count());
        $this->assertSame('001/2026', $this->create()->number);
    }

    public function test_assigning_without_a_transaction_is_refused(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->numbers->assign(new Offer, 2026);
    }

    public function test_a_future_year_is_refused_and_nothing_is_counted(): void
    {
        try {
            DB::transaction(fn () => $this->numbers->assign(new Offer, 2027));
            $this->fail('A future year should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DB::table('offer_counters')->count());
    }

    public function test_the_year_of_the_current_timezone_is_the_limit(): void
    {
        // 00:30 on New Year's Day in Belgrade is still 2026 in UTC: the app timezone decides.
        $this->travelTo(Carbon::parse('2027-01-01 00:30:00', config('app.timezone')));
        $this->assertSame('2026', now('UTC')->format('Y'));

        $this->assertSame('001/2027', $this->create(2027)->number);

        $this->expectException(InvalidArgumentException::class);
        DB::transaction(fn () => $this->numbers->assign(new Offer, 2028));
    }

    public function test_the_assign_method_does_not_save_but_sets_the_fields_that_cannot_be_mass_assigned(): void
    {
        $offer = DB::transaction(fn () => $this->numbers->assign(new Offer, 2026));

        $this->assertFalse($offer->exists);
        $this->assertSame(0, Offer::count());
        $this->assertSame(['year' => 2026, 'seq' => 1, 'number' => '001/2026'], $offer->only(['year', 'seq', 'number']));
    }

    public function test_numbers_grow_naturally_past_999(): void
    {
        DB::table('offer_counters')->insert(['year' => 2026, 'last_seq' => 998]);

        $this->assertSame('999/2026', $this->create()->number);
        $this->assertSame('1000/2026', $this->create()->number);
        $this->assertSame('1001/2026', $this->create()->number);
    }

    public function test_a_deleted_offer_leaves_a_gap_and_its_number_is_not_reused(): void
    {
        $this->create();
        $second = $this->create();

        $second->delete();

        $this->assertSame('003/2026', $this->create()->number);
    }

    public function test_the_unique_keys_stay_the_final_guard(): void
    {
        $this->create();
        // A counter that went wrong (restored from an old copy) must fail loudly, not repeat.
        DB::table('offer_counters')->where('year', 2026)->update(['last_seq' => 0]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->create();
    }

    public function test_the_migration_fills_the_counter_from_existing_offers(): void
    {
        Offer::factory()->create(['year' => 2026, 'seq' => 5, 'number' => '005/2026']);
        Offer::factory()->create(['year' => 2026, 'seq' => 9, 'number' => '009/2026']);
        Offer::factory()->create(['year' => 2025, 'seq' => 3, 'number' => '003/2025']);
        Schema::drop('offer_counters');

        $migration = require database_path('migrations/2026_10_11_100000_create_offer_counters_table.php');
        $migration->up();

        $this->assertSame(
            [2025 => 3, 2026 => 9],
            DB::table('offer_counters')->pluck('last_seq', 'year')->map(fn ($value) => (int) $value)->sortKeys()->all(),
        );
        $this->assertSame('010/2026', $this->create()->number);
    }

    public function test_the_counter_is_technical_and_not_an_offer_table_for_the_purge(): void
    {
        $this->assertNotContains('offer_counters', config('catalog.offer_tables'));
        $this->assertTrue(Schema::hasColumns('offer_counters', ['year', 'last_seq']));
        $this->assertArrayNotHasKey('App\\Models\\Concerns\\LogsActivity', class_uses(OfferCounter::class));
    }
}
