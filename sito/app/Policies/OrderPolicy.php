<?php

namespace App\Policies;

use App\Models\User;

/** Ordini in sola lettura (gli importi solo con orders.view_amounts); le azioni hanno ciascuna il suo permesso. */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function refund(User $user): bool
    {
        return $user->can('orders.refund');
    }

    /** Emissione e revoca degli omaggi. */
    public function issueComplimentary(User $user): bool
    {
        return $user->can('complimentary.issue');
    }

    /** Ordini needs_review: emetti i biglietti o rimborsa. */
    public function review(User $user): bool
    {
        return $user->can('orders.review');
    }

    public function reconcile(User $user): bool
    {
        return $user->can('reconciliation.run');
    }
}
