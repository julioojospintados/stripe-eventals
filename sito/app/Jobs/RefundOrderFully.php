<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\StaffAlert;
use App\Models\Order;
use App\Services\StripeGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rimborso automatico di un ordine needs_refund (pagato senza posti). Idempotente: stessa Idempotency-Key e una sola
 * riga refunds per ordine; lo stato refunded arriva poi dal webhook charge.refunded.
 */
class RefundOrderFully implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public string $orderId)
    {
        $this->onQueue('critical');
    }

    public function handle(StripeGateway $stripe): void
    {
        $order = Order::findOrFail($this->orderId);
        if ($order->status !== OrderStatus::NeedsRefund || ! $order->stripe_payment_intent_id) {
            return;
        }

        $key = 'auto-refund-'.$order->id;
        $refund = $order->refunds()->firstOrCreate(['idempotency_key' => $key], [
            'amount_cents' => $order->stripe_amount_total_cents ?: $order->total_cents, 'currency' => $order->currency,
            'cause' => 'no_capacity', 'status' => 'pending', 'source' => 'auto',
        ]);
        if ($refund->stripe_refund_id) {
            return;
        }

        $result = $stripe->createRefund([
            'payment_intent' => $order->stripe_payment_intent_id,
            'reason' => 'requested_by_customer',
            'metadata' => ['order_id' => $order->id, 'cause' => 'no_capacity'],
        ], $key);

        $refund->update(['stripe_refund_id' => $result['id'], 'status' => $result['status']]);
    }

    public function failed(Throwable $e): void
    {
        StaffAlert::raise('Rimborso automatico fallito', ["Ordine {$this->orderId}: {$e->getMessage()}", 'Va fatto a mano dalla Dashboard Stripe.']);
    }
}
