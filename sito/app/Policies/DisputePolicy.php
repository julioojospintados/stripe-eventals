<?php

namespace App\Policies;

use App\Models\User;

/** Contestazioni in sola lettura: le aggiornano gli eventi Stripe, la risposta si dà dalla Dashboard. */
class DisputePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('disputes.view');
    }

    public function view(User $user): bool
    {
        return $user->can('disputes.view');
    }
}
