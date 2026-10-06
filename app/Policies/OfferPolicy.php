<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Offers. A client creates an offer for themselves (the owner is the signed-in user, never taken
 * from the request); an admin creates one on behalf of a client they choose (4.5d): the owner is
 * then that client, never the admin. Everyone signed in may open the list (it is narrowed to their
 * own offers by the query); an admin sees any offer, a client only their own. Someone else's offer
 * is "not found", not "forbidden": offer numbers are consecutive, so a 403 would reveal that a
 * number exists. Choosing a client (searching the clients) is for the admin only.
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
        return $user->isAdmin() || $user->isClient();
    }

    /** Searching the clients to make an offer for one of them. */
    public function chooseClient(User $user): bool
    {
        return $user->isAdmin();
    }
}
