<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\StripeEventStatus;
use App\Mail\StaffAlert;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Services\MembershipService;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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

    public function handle(PaymentService $payments, MembershipService $memberships): void
    {
        try {
            DB::transaction(function () use ($payments, $memberships) {
                $event = StripeEvent::lockForUpdate()->findOrFail($this->eventId);
                if (in_array($event->status, [StripeEventStatus::Processed, StripeEventStatus::Ignored], true)) {
                    return;
                }

                $handled = $this->apply($event->type, $event->payload['data']['object'], $event->stripe_created_at, $payments, $memberships);

                $event->update([
                    'status' => $handled ? StripeEventStatus::Processed : StripeEventStatus::Ignored,
                    'processed_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null,
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
        'charge.refunded',
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required',
    ];

    /** false = evento che non ci riguarda (segnato ignored). */
    private function apply(string $type, array $object, Carbon $at, PaymentService $payments, MembershipService $memberships): bool
    {
        if (! in_array($type, self::HANDLED, true)) {
            return false;
        }

        if (str_starts_with($type, 'checkout.session.') && $object['mode'] === 'subscription') {
            if ($type === 'checkout.session.completed') {
                $memberships->activateFromCheckout($object);
            }

            return true;
        }

        if (str_starts_with($type, 'checkout.session.')) {
            $order = Order::findOrFail($object['metadata']['order_id'] ?? $object['client_reference_id']);
            match ($type) {
                'checkout.session.async_payment_succeeded' => $payments->markPaid($order, $object),
                'checkout.session.async_payment_failed' => $payments->close($order, OrderStatus::Failed, 'payment_failed', $object),
                default => $payments->applySession($order, $object),  // completed, expired
            };
        } elseif ($type === 'charge.refunded') {
            $payments->recordRefund($object);
        } elseif (str_starts_with($type, 'customer.subscription.')) {
            $memberships->sync($object['id'], $at);
        } elseif ($sub = $object['parent']['subscription_details']['subscription'] ?? $object['subscription'] ?? null) {
            $memberships->sync($sub, $at);  // invoice.*: la subscription sta in parent nelle versioni recenti dell'API
        }

        return true;
    }
}
