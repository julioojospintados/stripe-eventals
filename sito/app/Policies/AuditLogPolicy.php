<?php

namespace App\Policies;

use App\Models\User;

/** Registro audit in sola lettura: permesso audit_log.view. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit_log.view');
    }

    public function view(User $user): bool
    {
        return $user->can('audit_log.view');
    }
}
