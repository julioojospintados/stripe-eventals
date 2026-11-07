<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pool di posti: il database garantisce held + confirmed <= capacity. held e confirmed si cambiano solo con
 * l'UPDATE condizionale del CapacityService (passo 2), mai con save() su un valore letto prima.
 */
#[Fillable(['event_id', 'name', 'capacity', 'max_per_order'])]
class CapacityPool extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'held' => 'integer',
            'confirmed' => 'integer',
            'max_per_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsToMany<TicketType, $this> */
    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class, 'ticket_type_pools', 'pool_id', 'ticket_type_id');
    }

    /** @return HasMany<CapacityHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(CapacityHold::class, 'pool_id');
    }
}
