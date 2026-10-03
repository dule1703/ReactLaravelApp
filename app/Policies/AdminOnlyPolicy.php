<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the catalog entities: only admins may do anything. Subclasses exist so Laravel's
 * policy auto-discovery finds one per model.
 */
abstract class AdminOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }
}
