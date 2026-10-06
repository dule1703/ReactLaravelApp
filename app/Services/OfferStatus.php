<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes the status of an offer (4.6b): withdrawn by the client, the
 * withdrawal undone by an admin. A withdrawn offer stays readable; it only can no longer be
 * changed (editing comes later). Each method is idempotent: repeating it changes nothing and
 * writes no log entry, and returns false. The row is locked, so two requests cannot both log.
 * The entry ("offer.withdrawn" / "offer.withdrawal_reverted") comes from the model's own update
 * event, so there is no second "offer.updated".
 */
class OfferStatus
{
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

    private function setWithdrawnAt(Offer $offer, $value): bool
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
