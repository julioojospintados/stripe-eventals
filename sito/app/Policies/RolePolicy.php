<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Ruoli e permessi (Spatie Role, registrata in AppServiceProvider). Anche il ruolo admin si modifica, ma tiene sempre
 * StaffRole::ADMIN_LOCKED (lo rimette RoleResource), così nessuno si chiude fuori; i ruoli non si creano né si
 * cancellano dal pannello.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.update');
    }
}
