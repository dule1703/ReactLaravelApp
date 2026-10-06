<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Offers: only a client creates an offer, and always for themselves (the owner is the signed-in
 * user, never taken from the request); an admin creating one on behalf of a client comes with
 * 4.5d. Everyone signed in may open the list (it is narrowed to their own offers by the query);
 * an admin sees any offer, a client only their own. Someone else's offer is "not found", not
 * "forbidden": offer numbers are consecutive, so a 403 would reveal that a number exists.
 * Editing and deleting come later.
 */
class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isClient();
    }

    public function view(User $user, Offer $offer): Response
    {
        return $user->isAdmin() || $offer->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isClient();
    }
}
