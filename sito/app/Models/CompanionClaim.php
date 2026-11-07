<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Posto da accompagnatore su un pass: uno attivo per (pass, slot). */
#[Fillable(['host_ticket_id', 'slot', 'order_id', 'order_item_id', 'released_at', 'release_reason'])]
class CompanionClaim extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['slot' => 'integer', 'released_at' => 'datetime'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function hostTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'host_ticket_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
