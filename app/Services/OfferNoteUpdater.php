<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * The only place that edits an offer (4.6c), and what it edits is the note. Items, prices, the VAT
 * rate, the client snapshot, the number and the date never change (the offer is a document; another
 * configuration is a new offer). The row is locked and the status is checked again under the lock,
 * so a withdrawal that wins the race stops the edit (a deleted offer is "not found"). Saving the same
 * value changes nothing and writes no entry; the entry ("offer.note_updated") comes from the model's
 * update event and carries only the field name, never the text.
 */
class OfferNoteUpdater
{
    /**
     * @return bool true when the note was changed, false when it already had this value
     *
     * @throws OfferNotEditableException when the offer is withdrawn
     */
    public function update(Offer $offer, ?string $note): bool
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($offer, $note) {
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->isWithdrawn()) {
                throw new OfferNotEditableException(__('A withdrawn offer can no longer be changed.'));
            }

            if ($locked->note === $note) {
                return false;
            }

            $locked->forceFill(['note' => $note])->save();
            $offer->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
