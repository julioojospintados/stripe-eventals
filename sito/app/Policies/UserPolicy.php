<?php

namespace App\Policies;

use App\Models\User;

/** Utenti dello staff: solo admin. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('system.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('system.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('system.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('system.manage');
    }
}
