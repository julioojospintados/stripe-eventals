<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'title', 'summary', 'description', 'status', 'timezone', 'starts_on', 'ends_on', 'venue_name',
    'venue_address', 'currency', 'sales_open_at', 'sales_close_at'])]
class Event extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Senza valore esplicito valgono quelli dell'installazione (config/eventals.php), mai un evento in particolare.
        static::creating(function (Event $event) {
            $event->timezone ??= config('eventals.timezone');
            $event->currency = strtolower($event->currency ?? config('eventals.currency'));
        });
    }

    /**
     * Evento delle pagine pubbliche: quello di EVENTALS_EVENT se impostato, altrimenti il prossimo pubblicato non ancora
     * finito, altrimenti l'ultimo pubblicato. Null se non ce n'è nessuno.
     */
    public static function current(): ?self
    {
        if ($slug = config('eventals.event')) {
            return self::published()->where('slug', $slug)->first();
        }

        return self::published()->whereDate('ends_on', '>=', today())->oldest('starts_on')->first()
            ?? self::published()->latest('starts_on')->first();
    }

    /** Evento proposto nei form del pannello: quello pubblico, altrimenti l'ultimo creato per data. */
    public static function defaultId(): ?string
    {
        return self::current()->id ?? self::latest('starts_on')->value('id');
    }

    /** Fuso con cui mostrare e scrivere le ore di un record dell'evento; senza evento, quello dell'installazione. */
    public static function timezoneOf(?string $eventId): string
    {
        return ($eventId ? self::whereKey($eventId)->value('timezone') : null) ?? config('eventals.timezone');
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'summary' => 'array',
            'description' => 'array',
            'status' => EventStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'sales_open_at' => 'datetime',
            'sales_close_at' => 'datetime',
        ];
    }

    /** Ci sono già prezzi o ordini: la valuta non si cambia più (trigger events_currency_locked). */
    public function hasSalesData(): bool
    {
        return $this->orders()->exists()
            || TicketPrice::whereHas('ticketType', fn (Builder $q) => $q->where('event_id', $this->id))->exists();
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /** @return HasMany<EventDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class)->orderBy('day');
    }

    /** @return HasMany<ProgramSession, $this> */
    public function programSessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('position');
    }

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('position');
    }

    /** @return HasMany<TicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('position');
    }

    /** @return HasMany<PriceTier, $this> */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('starts_at');
    }

    /** @return HasMany<CapacityPool, $this> */
    public function capacityPools(): HasMany
    {
        return $this->hasMany(CapacityPool::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<DiscountCode, $this> */
    public function discountCodes(): HasMany
    {
        return $this->hasMany(DiscountCode::class);
    }
}
