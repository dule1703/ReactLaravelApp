<?php

namespace App\Policies;

use App\Models\ClientProfile;
use App\Models\User;

/**
 * No create/delete: a profile is created with the client and removed with the user (cascade).
 */
class ClientProfilePolicy
{
    public function view(User $user, ClientProfile $profile): bool
    {
        return $user->isAdmin() || $profile->user_id === $user->id;
    }

    public function update(User $user, ClientProfile $profile): bool
    {
        return $this->view($user, $profile);
    }
}
