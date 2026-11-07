<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\StaffAlert;
use App\Models\Order;
use App\Models\Refund;
use App\Services\PaymentService;
use App\Services\StripeGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rimborso completo di un ordine. Automatico per needs_refund (pagato senza posti, causa no_capacity); dal pannello
 * (commerciale o admin) anche per un ordine pagato, con la causa (event_cancelled, other). Idempotente: stessa
 * Idempotency-Key e una sola riga refunds per ordine e origine; lo stato refunded arriva poi dal webhook charge.refunded.
 */
class RefundOrderFully implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public string $orderId,
        public string $cause = 'no_capacity',
        public ?int $requestedBy = null,
        public ?string $reason = null,
    ) {
        $this->onQueue('critical');
    }

    public function handle(StripeGateway $stripe): void
    {
        $order = Order::findOrFail($this->orderId);
        $staff = $this->requestedBy !== null;
        $refundable = [OrderStatus::NeedsRefund, ...($staff ? [OrderStatus::Paid, OrderStatus::PartiallyRefunded] : [])];
        if (! in_array($order->status, $refundable, true) || ! $order->stripe_payment_intent_id) {
            return;
        }

        // Dopo un rimborso rifiutato da Stripe serve una chiave nuova, altrimenti Stripe ripete la risposta vecchia.
        $failed = $order->refunds()->whereIn('status', Refund::NOT_MONEY)->count();
        $key = ($staff ? 'staff-refund-' : 'auto-refund-').$order->id.($failed ? "-retry-$failed" : '');
        $paid = $order->stripe_amount_total_cents ?: $order->total_cents;
        $refunded = (int) $order->refunds()->whereNotIn('status', Refund::NOT_MONEY)->where(fn ($q) => $q->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $key))->sum('amount_cents');
        if ($paid - $refunded <= 0) {
            return;
        }
        $refund = $order->refunds()->firstOrCreate(['idempotency_key' => $key], [
            'amount_cents' => $paid - $refunded, 'currency' => $order->currency, 'cause' => $this->cause,
            'reason' => $this->reason, 'status' => 'pending', 'source' => $staff ? 'staff' : 'auto',
            'requested_by' => $this->requestedBy,
        ]);
        if ($refund->stripe_refund_id) {
            return;
        }

        $result = $stripe->createRefund([
            'payment_intent' => $order->stripe_payment_intent_id,
            'reason' => 'requested_by_customer',
            'amount' => $refund->amount_cents,
            'metadata' => ['order_id' => $order->id, 'cause' => $this->cause],
        ], $key);

        $refund->update(['stripe_refund_id' => $result['id']]);
        in_array($result['status'], Refund::NOT_MONEY, true)
            ? app(PaymentService::class)->refundFailed($result + ['payment_intent' => $order->stripe_payment_intent_id, 'amount' => $refund->amount_cents])
            : $refund->update(['status' => $result['status']]);
    }

    public function failed(Throwable $e): void
    {
        StaffAlert::raise('Rimborso automatico fallito', ["Ordine {$this->orderId}: {$e->getMessage()}", 'Va fatto a mano dalla Dashboard Stripe.']);
    }
}
