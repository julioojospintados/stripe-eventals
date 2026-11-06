<?php

namespace App\Services;

use App\Enums\CapacityHoldStatus;
use App\Models\CapacityHold;
use App\Models\CapacityPool;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posti (docs/architettura.md §7.2). Ogni metodo va chiamato dentro DB::transaction, dopo il lock sull'ordine: i pool
 * si bloccano in ordine di id (niente deadlock) e held/confirmed cambiano solo con UPDATE condizionali.
 */
class CapacityService
{
    /** Stripe non accetta sessioni più brevi di 30 minuti: la riserva deve durare almeno tanto. */
    public const MIN_HOLD_MINUTES = 30;

    /** @param array<string, int> $quantities pool_id => posti */
    public function hold(Order $order, array $quantities, Carbon $expiresAt): void
    {
        if ($expiresAt->lt(now()->addMinutes(self::MIN_HOLD_MINUTES))) {
            throw new ShopException('hold_too_short');
        }

        foreach ($this->lockPools(array_keys($quantities)) as $pool) {
            $n = $quantities[$pool->id];
            $taken = CapacityPool::whereKey($pool->id)
                ->whereRaw('held + confirmed + ? <= capacity', [$n])
                ->increment('held', $n);
            if ($taken === 0) {
                throw new ShopException('sold_out');
            }
            $order->holds()->create([
                'pool_id' => $pool->id, 'quantity' => $n, 'status' => CapacityHoldStatus::Active, 'expires_at' => $expiresAt,
            ]);
        }
    }

    /** Pagamento nei tempi: i posti in riserva diventano venduti. */
    public function convert(Order $order): void
    {
        $holds = $this->holds($order, [CapacityHoldStatus::Active, CapacityHoldStatus::AwaitingPayment]);
        $this->lockPools($holds->pluck('pool_id')->all());

        foreach ($holds as $hold) {
            CapacityPool::whereKey($hold->pool_id)->update([
                'held' => DB::raw('held - '.(int) $hold->quantity),
                'confirmed' => DB::raw('confirmed + '.(int) $hold->quantity),
            ]);
            $hold->update(['status' => CapacityHoldStatus::Converted, 'expires_at' => null]);
        }
    }

    /** Pagamento tardivo: riprenota i posti rilasciati se ci sono ancora tutti, altrimenti false e nulla cambia. */
    public function rebook(Order $order): bool
    {
        $holds = $this->holds($order, [CapacityHoldStatus::Released]);
        $pools = $this->lockPools($holds->pluck('pool_id')->all())->keyBy('id');

        foreach ($holds as $hold) {
            $pool = $pools[$hold->pool_id];
            if ($pool->held + $pool->confirmed + $hold->quantity > $pool->capacity) {
                return false;
            }
        }

        foreach ($holds as $hold) {
            CapacityPool::whereKey($hold->pool_id)->increment('confirmed', $hold->quantity);
            $hold->update(['status' => CapacityHoldStatus::Converted, 'expires_at' => null]);
        }

        return true;
    }

    /** Rilascio dopo la conferma di Stripe (sessione scaduta, pagamento fallito, ordine sostituito). */
    public function release(Order $order, string $reason): void
    {
        $holds = $this->holds($order, [CapacityHoldStatus::Active, CapacityHoldStatus::AwaitingPayment]);
        $this->lockPools($holds->pluck('pool_id')->all());

        foreach ($holds as $hold) {
            CapacityPool::whereKey($hold->pool_id)->decrement('held', $hold->quantity);
            $this->markReleased($hold, $reason);
        }
    }

    /** Ordine pagato e poi rimborsato: i posti venduti tornano liberi. */
    public function releaseConfirmed(Order $order, string $reason): void
    {
        $holds = $this->holds($order, [CapacityHoldStatus::Converted]);
        $this->lockPools($holds->pluck('pool_id')->all());

        foreach ($holds as $hold) {
            CapacityPool::whereKey($hold->pool_id)->decrement('confirmed', $hold->quantity);
            $this->markReleased($hold, $reason);
        }
    }

    private function markReleased(CapacityHold $hold, string $reason): void
    {
        $hold->update([
            'status' => CapacityHoldStatus::Released, 'released_at' => now(), 'release_reason' => $reason, 'expires_at' => null,
        ]);
    }

    private function holds(Order $order, array $statuses): Collection
    {
        return $order->holds()->whereIn('status', $statuses)->orderBy('pool_id')->get();
    }

    /** @param list<string> $ids */
    private function lockPools(array $ids): Collection
    {
        return CapacityPool::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }
}
