<?php

namespace App\Policies;

/** Evento e giorni: permessi event.*. */
class EventPolicy extends ResourcePolicy
{
    protected string $resource = 'event';
}
