<?php

namespace App\Policies;

use App\Models\User;

/** Membership in sola lettura (si modificano in Stripe): commerciale e admin. */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('memberships.view');
    }

    public function view(User $user): bool
    {
        return $user->can('memberships.view');
    }
}
