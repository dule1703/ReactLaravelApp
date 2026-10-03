<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->is($model);
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->is($model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model) && ! $model->isAdmin();
    }
}
