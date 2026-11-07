<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'ticket_id', 'amount_cents', 'currency', 'cause', 'reason', 'status', 'source',
    'stripe_refund_id', 'idempotency_key', 'needs_review', 'requested_by'])]
class Refund extends Model
{
    use HasUuids;

    /** Stati di un rimborso che non ha restituito soldi. */
    public const NOT_MONEY = ['failed', 'canceled'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'needs_review' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
