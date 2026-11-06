<?php

namespace App\Policies;

use App\Models\User;

/** Registro audit in sola lettura: solo admin. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('system.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('system.manage');
    }
}
