<?php

namespace App\Models;

use App\Enums\ProgramSessionAccess;
use App\Enums\SessionFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sessione del programma. Si salva con App\Services\ProgramService::save (sala libera, giorno dell'evento); un
 * workshop a pagamento ha il suo tipo di biglietto (ticket_types.program_session_id) con la capienza.
 */
#[Fillable(['event_id', 'code', 'event_day_id', 'room_id', 'track_id', 'slug', 'title', 'abstract', 'format', 'access',
    'starts_at', 'ends_at', 'published'])]
class ProgramSession extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'abstract' => 'array',
            'format' => SessionFormat::class,
            'access' => ProgramSessionAccess::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventDay, $this> */
    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return HasMany<SessionPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(SessionPart::class)->orderBy('position');
    }

    /** @return HasMany<SessionSpeaker, $this> */
    public function sessionSpeakers(): HasMany
    {
        return $this->hasMany(SessionSpeaker::class)->orderBy('position');
    }

    /** @return BelongsToMany<Speaker, $this> */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'session_speaker')->withPivot('role', 'position')->orderByPivot('position');
    }

    /**
     * Il tipo di biglietto del workshop.
     *
     * @return HasOne<TicketType, $this>
     */
    public function ticketType(): HasOne
    {
        return $this->hasOne(TicketType::class);
    }
}
