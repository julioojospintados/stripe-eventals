<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\StripeEventStatus;
use App\Mail\StaffAlert;
use App\Models\Order;
use App\Models\Refund;
use App\Models\StripeEvent;
use App\Services\DisputeService;
use App\Services\MembershipService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Elabora un evento salvato dal webhook, una volta sola: lock sulla riga di stripe_events, uscita se già processed;
 * i Service bloccano poi l'ordine e cambiano stato solo da pending/processing (docs/architettura.md §6.6).
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $eventId)
    {
        $this->onQueue('critical');
    }

    public function handle(PaymentService $payments, MembershipService $memberships, DisputeService $disputes): void
    {
        try {
            DB::transaction(function () use ($payments, $memberships, $disputes) {
                $event = StripeEvent::lockForUpdate()->findOrFail($this->eventId);
                if (in_array($event->status, [StripeEventStatus::Processed, StripeEventStatus::Ignored], true)) {
                    return;
                }

                $ignored = $this->apply($event->type, $event->payload['data']['object'], $event->stripe_created_at, $payments, $memberships, $disputes);

                $event->update([
                    'status' => $ignored ? StripeEventStatus::Ignored : StripeEventStatus::Processed,
                    'ignored_reason' => $ignored, 'processed_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null,
                ]);
            });
        } catch (Throwable $e) {
            StripeEvent::whereKey($this->eventId)->update([
                'status' => StripeEventStatus::Failed, 'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'attempts' => DB::raw('attempts + 1'),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        StaffAlert::raise('Evento Stripe non elaborato', ["{$this->eventId}: {$e->getMessage()}"]);
    }

    private const HANDLED = [
        'checkout.session.completed', 'checkout.session.expired',
        'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
        'charge.refunded', 'refund.failed', 'refund.updated', 'charge.refund.updated',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required',
    ];

    /** Elabora l'evento; restituisce null se fatto, altrimenti il motivo per cui è ignorato (salvato in ignored_reason). */
    private function apply(string $type, array $object, Carbon $at, PaymentService $payments, MembershipService $memberships, DisputeService $disputes): ?string
    {
        if (! in_array($type, self::HANDLED, true)) {
            return 'unhandled_type';
        }

        if (str_starts_with($type, 'checkout.session.') && $object['mode'] === 'subscription') {
            if ($type === 'checkout.session.completed') {
                $memberships->activateFromCheckout($object);
            }

            return null;
        }

        if (str_starts_with($type, 'checkout.session.')) {
            // Ordine sconosciuto: un altro ambiente sullo stesso account Stripe, o una sessione creata a mano.
            $order = Order::find($object['metadata']['order_id'] ?? $object['client_reference_id'] ?? null);
            if (! $order) {
                return 'unknown_order';
            }
            match ($type) {
                'checkout.session.async_payment_succeeded' => $payments->markPaid($order, $object),
                'checkout.session.async_payment_failed' => $payments->close($order, OrderStatus::Failed, 'payment_failed', $object),
                default => $payments->applySession($order, $object),  // completed, expired
            };
        } elseif ($type === 'charge.refunded') {
            $result = $payments->recordRefund($object);
        } elseif (in_array($type, ['refund.failed', 'refund.updated', 'charge.refund.updated'], true)) {
            if (! in_array($object['status'], Refund::NOT_MONEY, true)) {
                return null;   // pending o succeeded: lo stato dell'ordine lo porta charge.refunded
            }
            $result = $payments->refundFailed($object);
        } elseif (str_starts_with($type, 'charge.dispute.')) {
            $result = $disputes->apply($object);
        } elseif (str_starts_with($type, 'customer.subscription.')) {
            $memberships->sync($object['id'], $at);
        } elseif ($type === 'invoice.paid' && isset($object['metadata']['order_id'])) {
            $result = $payments->attachInvoice($object);   // fattura del Checkout biglietti (invoice_creation)
        } elseif ($sub = $object['parent']['subscription_details']['subscription'] ?? $object['subscription'] ?? null) {
            // invoice.*: la subscription sta in parent nelle versioni recenti dell'API
            $memberships->sync($sub, $at, $type === 'invoice.paid' ? ($object['hosted_invoice_url'] ?? null) : null);
        } else {
            return 'no_subscription';
        }

        return ($result ?? null) === 'unknown_order' ? 'unknown_order' : null;
    }
}
