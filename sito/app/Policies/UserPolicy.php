<?php

namespace App\Policies;

/** Utenti dello staff: permessi staff.*. */
class UserPolicy extends ResourcePolicy
{
    protected string $resource = 'staff';
}
