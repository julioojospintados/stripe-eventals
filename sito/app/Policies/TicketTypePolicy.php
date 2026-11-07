<?php

namespace App\Policies;

/** Tipi di biglietto: permessi ticket_types.*. */
class TicketTypePolicy extends ResourcePolicy
{
    protected string $resource = 'ticket_types';
}
