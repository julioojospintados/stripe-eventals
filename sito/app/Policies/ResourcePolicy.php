<?php

namespace App\Policies;

use App\Models\User;

/**
 * Vedi, crea, modifica, elimina con i permessi "<risorsa>.view|create|update|delete" (App\Enums\StaffRole::RESOURCES).
 * Le Policy controllano i permessi, mai il nome del ruolo.
 */
abstract class ResourcePolicy
{
    protected string $resource;

    public function viewAny(User $user): bool
    {
        return $user->can("{$this->resource}.view");
    }

    public function view(User $user): bool
    {
        return $user->can("{$this->resource}.view");
    }

    public function create(User $user): bool
    {
        return $user->can("{$this->resource}.create");
    }

    public function update(User $user): bool
    {
        return $user->can("{$this->resource}.update");
    }

    /** Riordino delle righe nelle tabelle del pannello (sale, tracce). */
    public function reorder(User $user): bool
    {
        return $user->can("{$this->resource}.update");
    }

    public function delete(User $user): bool
    {
        return $user->can("{$this->resource}.delete");
    }
}
