<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fascia di prezzo (early_bird, regular, last_minute): vale da starts_at (incluso) a ends_at (escluso), in UTC. */
#[Fillable(['event_id', 'code', 'name', 'starts_at', 'ends_at', 'active', 'position'])]
class PriceTier extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<TicketPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(TicketPrice::class);
    }
}
