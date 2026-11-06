<?php

namespace App\Policies;

use App\Models\User;

/** Biglietti in sola lettura; la correzione del nominativo è solo dell'admin. */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user): bool
    {
        return $user->can('orders.view');
    }

    /** Correzione di un refuso nel nominativo: solo admin. */
    public function correctAttendee(User $user): bool
    {
        return $user->can('tickets.correct_attendee');
    }
}
