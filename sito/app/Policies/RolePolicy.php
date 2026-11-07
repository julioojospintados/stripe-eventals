<?php

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Ruoli e permessi (Spatie Role, registrata in AppServiceProvider). Il ruolo admin ha sempre tutti i permessi e non si
 * modifica, così nessuno si chiude fuori; i ruoli non si creano né si cancellano dal pannello.
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
        return $user->can('roles.update') && $role->name !== StaffRole::Admin->value;
    }
}
