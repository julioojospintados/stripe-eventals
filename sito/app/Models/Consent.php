<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Consenso (o revoca) alla newsletter: solo in aggiunta, vale l'ultima riga per email. */
#[Fillable(['user_id', 'email', 'purpose', 'granted', 'source', 'locale', 'text_version', 'ip', 'user_agent', 'occurred_at'])]
class Consent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
