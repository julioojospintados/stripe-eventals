<?php

namespace App\Policies;

/** Codici sconto (ogni modifica nel registro audit): permessi discount_codes.*. */
class DiscountCodePolicy extends ResourcePolicy
{
    protected string $resource = 'discount_codes';
}
