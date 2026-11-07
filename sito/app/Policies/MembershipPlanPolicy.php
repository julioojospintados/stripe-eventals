<?php

namespace App\Policies;

use App\Models\User;

/** Prezzo della membership e Price ID Stripe: si vede e si modifica, non si crea né si cancella. */
class MembershipPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('membership_plans.view');
    }

    public function view(User $user): bool
    {
        return $user->can('membership_plans.view');
    }

    public function update(User $user): bool
    {
        return $user->can('membership_plans.update');
    }
}
