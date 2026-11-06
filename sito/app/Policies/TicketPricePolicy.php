<?php

namespace App\Policies;

use App\Models\User;

/** Vendita (tipi, fasce, prezzi, capienze): tutti leggono, organizzatore e admin modificano. Si spegne, non si cancella. */
class TicketPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user): bool
    {
        return $user->can('sales.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('sales.manage');
    }
}
