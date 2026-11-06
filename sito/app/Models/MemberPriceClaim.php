<?php

namespace App\Models;

use App\Enums\TicketKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prenotazione del prezzo membro: una sola attiva per (membro, evento, tipo, giorno, sessione), in ogni fascia. */
#[Fillable(['user_id', 'event_id', 'kind', 'event_day_id', 'program_session_id', 'order_id', 'order_item_id',
    'released_at', 'release_reason'])]
class MemberPriceClaim extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['kind' => TicketKind::class, 'released_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
