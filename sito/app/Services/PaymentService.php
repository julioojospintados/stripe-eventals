<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Jobs\RefundOrderFully;
use App\Jobs\SendTicketsEmail;
use App\Mail\StaffAlert;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Esito dei pagamenti, lo stesso dal webhook, dalla pagina di successo e dal cron (docs/architettura.md §6.6, §7.2).
 * Sempre: lock sull'ordine prima di tutto, transizioni solo da pending/processing (o pagamento tardivo), poi i pool.
 */
class PaymentService
{
    public function __construct(private CapacityService $capacity, private TicketService $tickets) {}

    /** Applica una Checkout Session letta da Stripe. Esiti: paid, paid_late, needs_refund, already_done, processing, expired, open. */
    public function applySession(Order $order, array $session): string
    {
        return match (true) {
            $session['status'] === 'complete' && in_array($session['payment_status'], ['paid', 'no_payment_required'], true) => $this->markPaid($order, $session),
            $session['status'] === 'complete' => $this->markProcessing($order, $session),
            $session['status'] === 'expired' => $this->close($order, OrderStatus::Expired, 'checkout_expired', $session) ? 'expired' : 'already_done',
            default => 'open',
        };
    }

    /** $session null solo per gli ordini a costo zero (codice al 100%). */
    public function markPaid(Order $order, ?array $session): string
    {
        return DB::transaction(function () use ($order, $session) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->checkSession($order, $session);

            $onTime = in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true);
            $late = in_array($order->status, [OrderStatus::Expired, OrderStatus::Failed, OrderStatus::Cancelled], true);
            if (! $onTime && ! $late) {
                return 'already_done';
            }

            $order->stripe_payment_intent_id = $session['payment_intent'] ?? null;
            $order->stripe_invoice_id ??= $session['invoice'] ?? null;
            $order->stripe_amount_total_cents = $session['amount_total'] ?? 0;

            if ($late && ! $this->capacity->rebook($order)) {
                // Soldi presi, posti finiti: nessun biglietto, rimborso automatico in coda nella stessa transazione.
                $this->transition($order, OrderStatus::NeedsRefund);
                RefundOrderFully::dispatch($order->id);
                StaffAlert::raise('Pagamento tardivo senza posti: rimborso automatico', [
                    "Ordine {$order->id} ({$order->buyer_email}), {$order->total_cents} centesimi.",
                ]);

                return 'needs_refund';
            }

            $late ? $this->retakeClaims($order) : $this->capacity->convert($order);

            // Importo diverso dal totale: posti tenuti, nessun biglietto finché lo staff non decide (approveReview / refundReview).
            // Anche la valuta della sessione deve essere quella dell'ordine (e quindi dell'evento): 399 USD non sono 399 EUR.
            // Valuta mancante = non verificabile: si rivede come una valuta diversa.
            $wrongCurrency = $session && strtolower((string) ($session['currency'] ?? '')) !== $order->currency;
            if ($session && ($order->stripe_amount_total_cents !== $order->total_cents || $wrongCurrency)) {
                $order->review_reason = 'amount_mismatch';
                $this->transition($order, OrderStatus::NeedsReview);
                StaffAlert::raise('Importo pagato diverso dall\'ordine: biglietti non emessi', [
                    "Ordine {$order->id}: Stripe {$order->stripe_amount_total_cents}, ordine {$order->total_cents} centesimi.",
                    'Decidi dal pannello (Orders, stato needs_review): emetti i biglietti o rimborsa.',
                ]);

                return 'needs_review';
            }

            $this->confirm($order);

            return $late ? 'paid_late' : 'paid';
        }, 3);
    }

    /** Ordine da rivedere: lo staff decide di emettere i biglietti (i posti sono già presi). */
    public function approveReview(Order $order, User $staff, string $note): void
    {
        DB::transaction(function () use ($order, $staff, $note) {
            $order = $this->lockForReview($order);
            $order->review_reason = null;
            $this->confirm($order);
            AuditLog::record('order.review_approved', $order, $staff, ['note' => $note]);
        }, 3);
    }

    /** Ordine da rivedere: lo staff decide di rimborsare; i posti tornano liberi e il rimborso va in coda. */
    public function refundReview(Order $order, User $staff, string $note): void
    {
        DB::transaction(function () use ($order, $staff, $note) {
            $order = $this->lockForReview($order);
            $this->capacity->releaseConfirmed($order, 'review_refund');
            $this->releaseClaims($order, 'review_refund');
            $this->transition($order, OrderStatus::NeedsRefund);
            RefundOrderFully::dispatch($order->id, 'other', $staff->id, $note);
            AuditLog::record('order.review_refunded', $order, $staff, ['note' => $note]);
        }, 3);
    }

    /**
     * Stripe ha rifiutato o annullato un rimborso (refund.failed, refund.updated): rimborso failed/canceled, ordine da
     * rivedere, avviso allo staff. Ordine e biglietti non cambiano stato: niente biglietti riattivati in silenzio.
     */
    public function refundFailed(array $refund): string
    {
        $row = Refund::where('stripe_refund_id', $refund['id'])->first();
        $order = $row->order ?? Order::where('stripe_payment_intent_id', $refund['payment_intent'] ?? '')->first();
        if (! $order) {
            return 'unknown_order';
        }

        return DB::transaction(function () use ($order, $refund) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $row = $order->refunds()->where('stripe_refund_id', $refund['id'])->first();
            if ($row && $row->status === $refund['status']) {
                return 'already_done';
            }
            $row
                ? $row->update(['status' => $refund['status'], 'needs_review' => true])
                : $order->refunds()->create([
                    'amount_cents' => $refund['amount'], 'currency' => $order->currency, 'status' => $refund['status'],
                    'source' => 'dashboard', 'stripe_refund_id' => $refund['id'], 'needs_review' => true,
                ]);
            $order->update(['review_reason' => 'refund_failed']);
            StaffAlert::raise('Rimborso rifiutato da Stripe', [
                "Ordine {$order->id} ({$order->status->value}): rimborso {$refund['id']} {$refund['status']}, motivo ".($refund['failure_reason'] ?? '—').'.',
                'Ordine e biglietti non sono cambiati: rifai il rimborso dal pannello o dalla Dashboard Stripe.',
            ]);

            return 'refund_failed';
        }, 3);
    }

    /** Sessione completata ma pagamento ancora in corso (async): i posti restano presi senza scadenza. */
    public function markProcessing(Order $order, array $session): string
    {
        return DB::transaction(function () use ($order, $session) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->checkSession($order, $session);
            if ($order->status !== OrderStatus::Pending) {
                return 'already_done';
            }
            $order->holds()->where('status', 'active')->update(['status' => 'awaiting_payment', 'expires_at' => null]);
            $this->transition($order, OrderStatus::Processing);

            return 'processing';
        }, 3);
    }

    /** Chiude un ordine non pagato (expired, failed, cancelled) e rilascia posti, prezzo membro e codice. */
    public function close(Order $order, OrderStatus $to, string $reason, ?array $session = null): bool
    {
        return DB::transaction(function () use ($order, $to, $reason, $session) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->checkSession($order, $session);
            if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true)
                || ! $order->status->canTransitionTo($to)) {
                return false;
            }

            $this->capacity->release($order, $reason);
            $this->releaseClaims($order, $reason);
            $this->releaseDiscount($order, $reason);
            $this->transition($order, $to);

            return true;
        }, 3);
    }

    /** invoice.paid della fattura del Checkout biglietti (metadata order_id): id e pagina pubblica sull'ordine. */
    public function attachInvoice(array $invoice): string
    {
        $order = Order::find($invoice['metadata']['order_id'] ?? null);
        if (! $order) {
            return 'unknown_order';
        }
        $order->update(['stripe_invoice_id' => $invoice['id'], 'stripe_invoice_url' => $invoice['hosted_invoice_url'] ?? $order->stripe_invoice_url]);

        return 'attached';
    }

    /** charge.refunded: registra i rimborsi (anche quelli fatti nella Dashboard) e aggiorna ordine e biglietti. */
    public function recordRefund(array $charge): string
    {
        $order = Order::where('stripe_payment_intent_id', $charge['payment_intent'] ?? '')->first();
        if (! $order) {
            return 'unknown_order';
        }

        return DB::transaction(function () use ($order, $charge) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            $known = (int) $order->refunds()->whereNotIn('status', Refund::NOT_MONEY)->sum('amount_cents');
            if ($charge['amount_refunded'] > $known) {
                $order->refunds()->create([
                    'amount_cents' => $charge['amount_refunded'] - $known, 'currency' => $order->currency,
                    'status' => 'succeeded', 'source' => 'dashboard', 'needs_review' => true,
                ]);
            }

            if (! $charge['refunded']) {
                if ($order->status === OrderStatus::Paid) {
                    $this->transition($order, OrderStatus::PartiallyRefunded);
                }

                return 'partially_refunded';
            }

            $order->refunds()->where('status', 'pending')->update(['status' => 'succeeded']);
            if ($order->status === OrderStatus::Refunded) {
                return 'already_done';
            }
            if (in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::NeedsReview], true)) {
                $this->capacity->releaseConfirmed($order, 'refunded');
                $this->releaseClaims($order, 'refunded');
                $order->tickets()->where('status', TicketStatus::Valid)
                    ->update(['status' => TicketStatus::Refunded, 'cancelled_at' => now()]);
            }
            $this->transition($order, OrderStatus::Refunded);

            return 'refunded';
        }, 3);
    }

    /** Pagato: biglietti emessi e inviati. */
    private function confirm(Order $order): void
    {
        $order->paid_at = now();
        $this->transition($order, OrderStatus::Paid);
        $this->tickets->issue($order);
        SendTicketsEmail::dispatchForOrder($order);
    }

    private function lockForReview(Order $order): Order
    {
        $order = Order::lockForUpdate()->findOrFail($order->id);
        if ($order->status !== OrderStatus::NeedsReview) {
            throw new ShopException('order_not_in_review');
        }

        return $order;
    }

    private function checkSession(Order $order, ?array $session): void
    {
        $expected = $order->total_cents === 0 ? null : $order->stripe_checkout_session_id;
        if (($session['id'] ?? null) !== $expected) {
            throw new ShopException('session_mismatch');
        }
    }

    /** Stesse regole del trigger orders_guard, anche sugli hosting senza trigger. */
    private function transition(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to, $order->origin)) {
            throw new LogicException("order_invalid_transition {$order->status->value}>{$to->value}");
        }
        $order->status = $to;
        $order->save();
    }

    private function releaseClaims(Order $order, string $reason): void
    {
        $order->memberPriceClaims()->whereNull('released_at')->update(['released_at' => now(), 'release_reason' => $reason]);
    }

    private function releaseDiscount(Order $order, string $reason): void
    {
        if (! $order->discount_reserved) {
            return;
        }
        $released = DB::table('discount_code_redemptions')->where('order_id', $order->id)->whereNull('released_at')
            ->update(['released_at' => now(), 'release_reason' => $reason]);
        if ($released) {
            DiscountCode::whereKey($order->discount_code_id)->where('redeemed_count', '>', 0)->decrement('redeemed_count');
        }
    }

    /** Pagamento tardivo: riprende prezzo membro e codice; se intanto non si può, il biglietto vale e lo staff lo sa. */
    private function retakeClaims(Order $order): void
    {
        foreach ($order->memberPriceClaims()->whereNotNull('released_at')->get() as $claim) {
            try {
                $claim->update(['released_at' => null, 'release_reason' => null]);
            } catch (UniqueConstraintViolationException) {
                StaffAlert::raise('member_price_limit_late', ["Ordine {$order->id}: secondo biglietto a prezzo membro dopo un pagamento tardivo."]);
            }
        }

        if ($order->discount_reserved) {
            $retaken = DB::table('discount_code_redemptions')->where('order_id', $order->id)->whereNotNull('released_at')
                ->update(['released_at' => null, 'release_reason' => null]);
            if ($retaken && DiscountCode::whereKey($order->discount_code_id)
                ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redeemed_count', '<', 'max_redemptions'))
                ->increment('redeemed_count') === 0) {
                StaffAlert::raise('discount_limit_late', ["Ordine {$order->id}: codice sconto oltre il limite dopo un pagamento tardivo."]);
            }
        }
    }
}
