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
}
