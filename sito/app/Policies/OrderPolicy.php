<?php

namespace App\Policies;

use App\Models\User;

/** Ordini in sola lettura (l'organizzatore senza importi); azioni di rimborso e omaggio per permesso. */
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

    /** Rimborso completo (evento annullato, pagato senza posti): commerciale e admin. */
    public function refund(User $user): bool
    {
        return $user->can('refunds.manage');
    }

    /** Emissione e revoca degli omaggi: organizzatore e admin. */
    public function issueComplimentary(User $user): bool
    {
        return $user->can('complimentary.manage');
    }
}
