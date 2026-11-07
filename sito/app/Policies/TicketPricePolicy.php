<?php

namespace App\Policies;

/** Prezzi tipo × fascia × ruolo: permessi ticket_prices.*. */
class TicketPricePolicy extends ResourcePolicy
{
    protected string $resource = 'ticket_prices';
}
