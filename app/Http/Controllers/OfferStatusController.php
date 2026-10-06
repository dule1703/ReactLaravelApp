<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Services\OfferStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Status changes of an offer (4.6b). Every action asks the Policy first (someone else's offer is
 * "not found"); the work is done by OfferStatus. Repeating an action is not an error: the page
 * only says that nothing changed.
 */
class OfferStatusController extends Controller
{
    public function withdraw(Offer $offer, OfferStatus $status): RedirectResponse
    {
        Gate::authorize('withdraw', $offer);

        $message = $status->withdraw($offer)
            ? __('Offer :number withdrawn.', ['number' => $offer->number])
            : __('Offer :number is already withdrawn.', ['number' => $offer->number]);

        return redirect()->route('offers.show', $offer)->with('success', $message);
    }

    public function revertWithdrawal(Offer $offer, OfferStatus $status): RedirectResponse
    {
        Gate::authorize('revertWithdrawal', $offer);

        $message = $status->revertWithdrawal($offer)
            ? __('The withdrawal of offer :number was undone.', ['number' => $offer->number])
            : __('Offer :number is not withdrawn.', ['number' => $offer->number]);

        return redirect()->route('offers.show', $offer)->with('success', $message);
    }

    public function destroy(Offer $offer, OfferStatus $status): RedirectResponse
    {
        Gate::authorize('delete', $offer);

        $message = $status->delete($offer)
            ? __('Offer :number deleted. You can restore it from the list of deleted offers.', ['number' => $offer->number])
            : __('Offer :number is already deleted.', ['number' => $offer->number]);

        return redirect()->route('offers.index')->with('success', $message);
    }

    public function restore(Offer $offer, OfferStatus $status): RedirectResponse
    {
        Gate::authorize('restore', $offer);

        $message = $status->restore($offer)
            ? __('Offer :number restored.', ['number' => $offer->number])
            : __('Offer :number is not deleted.', ['number' => $offer->number]);

        return redirect()->route('offers.index', ['status' => 'deleted'])->with('success', $message);
    }
}
