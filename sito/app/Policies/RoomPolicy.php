<?php

namespace App\Policies;

/** Sale: permessi rooms.*. */
class RoomPolicy extends ResourcePolicy
{
    protected string $resource = 'rooms';
}
