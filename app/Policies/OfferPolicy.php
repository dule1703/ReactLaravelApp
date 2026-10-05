<?php

namespace App\Policies;

use App\Models\User;

/**
 * Offers (4.5b): only a client creates an offer, and always for themselves (the owner is the
 * signed-in user, never taken from the request). An admin creating an offer on behalf of a
 * client comes with 4.5d; viewing, editing and deleting offers with 4.6/4.7.
 */
class OfferPolicy
{
    public function create(User $user): bool
    {
        return $user->isClient();
    }
}
