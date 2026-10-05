<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Assigns the number NNN/GGGG of an offer: (year, seq, number), the sequence restarts every year.
 *
 * Use inside ONE transaction that also saves the offer, with a retry for deadlocks:
 *
 *     DB::transaction(function () use (...) {
 *         $offer = (new Offer)->fill([...]);
 *         $offer->user_id = ...;
 *         $numbers->assign($offer, $offer->offer_date->year);
 *         $offer->save();
 *     }, 3);
 *
 * The year is the year of the offer date, which the SERVER sets (never the browser), and never
 * later than the current year. A rolled back transaction takes the increment with it, so a
 * failed offer leaves no gap. A deleted offer does leave one: a number is never reused.
 *
 * Why UPDATE first: the counter row is taken with an atomic "last_seq = last_seq + 1", which
 * locks the row exclusively at once. An INSERT / INSERT IGNORE on an existing key takes only a
 * shared lock first, and two requests going from shared to exclusive deadlock on MySQL. The
 * insert happens only when the year has no row yet (0 rows updated), and then the UPDATE is
 * repeated. SQLite has no row locks (it serializes writers), which is fine: the sequence is
 * still correct, the locking matters on MySQL. unique(year, seq) and unique(number) on `offers`
 * remain the last line of defence; their violation is an error, never silently repeated.
 */
class OfferNumber
{
    /**
     * Sets year, seq and number on the offer (not mass-assignable, so forceFill) and returns it;
     * the caller saves it in the same transaction.
     */
    public function assign(Offer $offer, int $year): Offer
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An offer number must be assigned inside the transaction that saves the offer.');
        }

        if ($year < 2000 || $year > (int) now()->format('Y')) {
            throw new InvalidArgumentException("The year $year is not valid for an offer number.");
        }

        $updated = $this->increment($year);

        if ($updated === 0) {
            DB::table('offer_counters')->insertOrIgnore(['year' => $year, 'last_seq' => 0]);
            $updated = $this->increment($year);
        }

        if ($updated !== 1) {
            throw new RuntimeException("The offer counter of $year could not be incremented.");
        }

        $seq = (int) DB::table('offer_counters')->where('year', $year)->value('last_seq');

        return $offer->forceFill([
            'year' => $year,
            'seq' => $seq,
            'number' => self::format($seq, $year),
        ]);
    }

    /** 12/2026 -> "012/2026"; past 999 the number just grows ("1000/2026"). */
    public static function format(int $seq, int $year): string
    {
        return sprintf('%03d/%04d', $seq, $year);
    }

    private function increment(int $year): int
    {
        return DB::table('offer_counters')->where('year', $year)->update(['last_seq' => DB::raw('last_seq + 1')]);
    }
}
