<?php

namespace App\Models;

use App\Enums\PriceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Riga d'ordine; attendees = [{"name": "...", "email": "..."}], uno per unità, diventano i nominativi dei biglietti. */
#[Fillable(['order_id', 'ticket_type_id', 'quantity', 'price_role', 'list_unit_amount_cents', 'unit_discount_cents',
    'unit_amount_cents', 'total_cents', 'stripe_line_name', 'ticket_price_id', 'attendees'])]
class OrderItem extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_role' => PriceRole::class,
            'list_unit_amount_cents' => 'integer',
            'unit_discount_cents' => 'integer',
            'unit_amount_cents' => 'integer',
            'total_cents' => 'integer',
            'attendees' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function ticketPrice(): BelongsTo
    {
        return $this->belongsTo(TicketPrice::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
