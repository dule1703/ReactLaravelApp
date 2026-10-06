<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes the status of an offer (4.6b): withdrawn by the client, the
 * withdrawal undone by an admin, deleted (soft) and restored by an admin. A withdrawn offer stays
 * readable and can no longer be changed (editing comes later); a deleted one is "not found" for
 * everyone and keeps its number (the number is never reused).
 *
 * Each method is idempotent: repeating it changes nothing, writes no log entry and returns false.
 * The row is locked, so two requests cannot both write an entry. The withdrawal entries come from
 * the model's own update event ("offer.withdrawn" / "offer.withdrawal_reverted", see Offer), the
 * delete and restore entries are written here, with no changes (nothing of the client's data).
 */
class OfferStatus
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** @return bool true when the offer was withdrawn now, false when it already was */
    public function withdraw(Offer $offer): bool
    {
        return $this->setWithdrawnAt($offer, now());
    }

    /** @return bool true when the withdrawal was undone now, false when the offer was not withdrawn */
    public function revertWithdrawal(Offer $offer): bool
    {
        return $this->setWithdrawnAt($offer, null);
    }

    /** @return bool true when the offer was deleted now, false when it already was */
    public function delete(Offer $offer): bool
    {
        return DB::transaction(function () use ($offer) {
            // The default scope hides a deleted offer: not found here means "already deleted".
            $locked = Offer::query()->lockForUpdate()->find($offer->id);

            if ($locked === null) {
                return false;
            }

            $locked->delete();
            $this->logger->log('offer.deleted', $locked);

            return true;
        });
    }

    /** @return bool true when the offer was restored now, false when it was not deleted */
    public function restore(Offer $offer): bool
    {
        return DB::transaction(function () use ($offer) {
            $locked = Offer::onlyTrashed()->lockForUpdate()->find($offer->id);

            if ($locked === null) {
                return false;
            }

            // The withdrawal status is kept as it was.
            $locked->restore();
            $this->logger->log('offer.restored', $locked);

            return true;
        });
    }

    private function setWithdrawnAt(Offer $offer, mixed $value): bool
    {
        return DB::transaction(function () use ($offer, $value) {
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if (($locked->withdrawn_at === null) === ($value === null)) {
                return false;
            }

            $locked->forceFill(['withdrawn_at' => $value])->save();
            $offer->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
