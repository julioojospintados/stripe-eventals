<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Codice di accesso a 6 cifre: solo l'hash, 10 minuti, 5 tentativi (LoginCodeService). */
#[Fillable(['email', 'code_hash', 'attempts', 'expires_at', 'used_at'])]
class LoginCode extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
