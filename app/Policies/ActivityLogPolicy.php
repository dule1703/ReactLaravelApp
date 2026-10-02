<?php

namespace App\Policies;

use App\Models\User;

/**
 * The log is append-only: there is deliberately no update/delete ability, not even for admins.
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
