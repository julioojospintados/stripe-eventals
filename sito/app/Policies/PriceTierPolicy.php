<?php

namespace App\Policies;

/** Fasce di prezzo: permessi price_tiers.*. */
class PriceTierPolicy extends ResourcePolicy
{
    protected string $resource = 'price_tiers';
}
