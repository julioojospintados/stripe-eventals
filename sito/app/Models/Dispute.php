<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contestazione di un pagamento (charge.dispute.*): la aggiornano solo gli eventi Stripe, si gestisce nella Dashboard. */
#[Fillable(['stripe_dispute_id', 'order_id', 'stripe_charge_id', 'amount_cents', 'currency', 'reason', 'status',
    'evidence_due_by', 'closed_at'])]
class Dispute extends Model
{
    use HasUuids;

    /** Stati di Stripe in cui la contestazione è chiusa. */
    public const CLOSED = ['won', 'lost', 'prevented', 'warning_closed'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'evidence_due_by' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
