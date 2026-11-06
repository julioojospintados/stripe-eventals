<?php

namespace App\Policies;

use App\Models\User;

/** Evento e giorni: tutti leggono, organizzatore e admin modificano. Nessuna cancellazione. */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('event.view');
    }

    public function view(User $user): bool
    {
        return $user->can('event.view');
    }

    public function create(User $user): bool
    {
        return $user->can('event.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('event.manage');
    }
}
