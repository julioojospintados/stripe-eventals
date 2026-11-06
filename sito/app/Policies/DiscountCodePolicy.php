<?php

namespace App\Policies;

use App\Models\User;

/** Codici sconto: admin, commerciale e organizzatore (decisione del cliente). Si disattivano, non si cancellano. */
class DiscountCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('discount_codes.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('discount_codes.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('discount_codes.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('discount_codes.manage');
    }
}
