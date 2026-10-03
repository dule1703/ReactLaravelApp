<?php

namespace App\Policies;

use App\Models\ClientProfile;
use App\Models\User;

/**
 * Owner or admin may view/update a profile. Listing, revealing sensitive values, deleting
 * the JMBG and deleting the client are admin-only. There is no create: a profile is created
 * with the client.
 */
class ClientProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ClientProfile $profile): bool
    {
        return $user->isAdmin() || $profile->user_id === $user->id;
    }

    public function update(User $user, ClientProfile $profile): bool
    {
        return $this->view($user, $profile);
    }

    /** Full JMBG/PIB values (every use is logged by the controller). */
    public function viewSensitive(User $user, ClientProfile $profile): bool
    {
        return $user->isAdmin();
    }

    public function deleteJmbg(User $user, ClientProfile $profile): bool
    {
        return $user->isAdmin();
    }

    /** An admin can never delete themselves or another admin. */
    public function delete(User $user, ClientProfile $profile): bool
    {
        return $user->isAdmin()
            && $profile->user_id !== $user->id
            && ! $profile->user->isAdmin();
    }
}
