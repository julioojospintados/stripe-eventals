<?php

namespace App\Policies;

/** Capienze: permessi capacity_pools.*. */
class CapacityPoolPolicy extends ResourcePolicy
{
    protected string $resource = 'capacity_pools';
}
