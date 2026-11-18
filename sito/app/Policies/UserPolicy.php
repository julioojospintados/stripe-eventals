<?php

namespace App\Policies;

use App\Models\User;

/** Utenti dello staff: permessi staff.*. */
class UserPolicy extends ResourcePolicy
{
    protected string $resource = 'staff';

    /** Lo staff non si elimina: gli si toglie il ruolo (StaffRole::NOT_DELETABLE). */
    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
