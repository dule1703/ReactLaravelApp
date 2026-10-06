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
 * Status (4.6b): the owner withdraws their own offer (an admin never does: 403; someone else's
 * offer is "not found"); only an admin reverts a withdrawal (someone who is not an admin gets "not
 * found" on someone else's offer, so nothing reveals that it exists). Deleting (soft) and restoring are
 * for the admin only; a client gets 403 on their own offer and "not found" on any other, and always
 * "not found" on a restore (a deleted offer does not exist for them).
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

    /** Only the note changes; never on a withdrawn offer (403, even for the owner and the admin). */
    public function update(User $user, Offer $offer): Response
    {
        if (! $user->isAdmin() && $offer->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $offer->isWithdrawn()
            ? Response::denyWithStatus(403, __('A withdrawn offer can no longer be changed.'))
            : Response::allow();
    }

    /** Only the owner withdraws; the admin does not. */
    public function withdraw(User $user, Offer $offer): Response
    {
        if ($user->isAdmin()) {
            return Response::denyWithStatus(403);
        }

        return $offer->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    /** Undoing a withdrawal is for the admin only. */
    public function revertWithdrawal(User $user, Offer $offer): Response
    {
        return $this->adminOnly($user, $offer);
    }

    public function delete(User $user, Offer $offer): Response
    {
        return $this->adminOnly($user, $offer);
    }

    public function restore(User $user, Offer $offer): Response
    {
        return $user->isAdmin() ? Response::allow() : Response::denyAsNotFound();
    }

    private function adminOnly(User $user, Offer $offer): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        return $offer->user_id === $user->id ? Response::denyWithStatus(403) : Response::denyAsNotFound();
    }
}
