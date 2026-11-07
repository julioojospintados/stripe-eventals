<?php

namespace App\Models;

use App\Enums\CapacityHoldStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'pool_id', 'quantity', 'status', 'expires_at', 'released_at', 'release_reason'])]
class CapacityHold extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => CapacityHoldStatus::class,
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CapacityPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(CapacityPool::class, 'pool_id');
    }
}
