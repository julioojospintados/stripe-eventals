<?php

namespace App\Models;

use App\Enums\ProgramSessionAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Sessione del programma; in Fase 1 servono solo i workshop a pagamento. */
#[Fillable(['event_id', 'code', 'event_day_id', 'slug', 'title', 'abstract', 'access', 'starts_at', 'ends_at', 'published'])]
class ProgramSession extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'abstract' => 'array',
            'access' => ProgramSessionAccess::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published' => 'boolean',
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

    /** Il tipo di biglietto del workshop. */
    public function ticketType(): HasOne
    {
        return $this->hasOne(TicketType::class);
    }
}
