<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sala dell'evento; position è la colonna nella griglia del programma. */
#[Fillable(['event_id', 'code', 'name', 'capacity', 'floor', 'position'])]
class Room extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['name' => 'array', 'capacity' => 'integer', 'floor' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<ProgramSession, $this> */
    public function programSessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }
}
