<?php

namespace App\Models;

use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_id', 'kind', 'channel', 'name', 'description', 'event_day_id', 'program_session_id', 'max_per_order',
    'max_per_host', 'sales_start', 'sales_end', 'active', 'position'])]
class TicketType extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => TicketKind::class,
            'channel' => TicketChannel::class,
            'name' => 'array',
            'description' => 'array',
            'max_per_order' => 'integer',
            'max_per_host' => 'integer',
            'sales_start' => 'datetime',
            'sales_end' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    public function programSession(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(TicketPrice::class);
    }

    /** Pool di posti consumati da un biglietto di questo tipo. */
    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(CapacityPool::class, 'ticket_type_pools', 'ticket_type_id', 'pool_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
