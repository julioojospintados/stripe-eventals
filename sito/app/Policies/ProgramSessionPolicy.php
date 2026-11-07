<?php

namespace App\Policies;

use App\Models\User;

/** Sessioni del programma (con interventi e speaker): permessi program_sessions.*; import CSV: program.import. */
class ProgramSessionPolicy extends ResourcePolicy
{
    protected string $resource = 'program_sessions';

    public function import(User $user): bool
    {
        return $user->can('program.import');
    }
}
