<?php

namespace App\Policies;

use App\Models\User;

/** Biglietti in sola lettura; correzione del nome e reinvio con i loro permessi. */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tickets.view');
    }

    public function view(User $user): bool
    {
        return $user->can('tickets.view');
    }

    /** Correzione di un refuso nel nominativo. */
    public function correctAttendee(User $user): bool
    {
        return $user->can('tickets.correct_attendee');
    }

    public function resend(User $user): bool
    {
        return $user->can('tickets.resend');
    }
}
