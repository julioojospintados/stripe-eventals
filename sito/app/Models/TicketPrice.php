<?php

namespace App\Models;

use App\Enums\PriceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prezzo per tipo × fascia × ruolo; price_tier_id null = prezzo base, valido nelle fasce senza un prezzo loro. */
#[Fillable(['ticket_type_id', 'price_tier_id', 'price_role', 'amount_cents', 'currency'])]
class TicketPrice extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // La valuta è sempre quella dell'evento del tipo (il trigger ticket_prices_same_event rifiuta le altre).
        static::creating(fn (TicketPrice $price) => $price->currency ??= $price->ticketType->event->currency);
    }

    protected function casts(): array
    {
        return ['price_role' => PriceRole::class, 'amount_cents' => 'integer'];
    }

    /** @return BelongsTo<TicketType, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
