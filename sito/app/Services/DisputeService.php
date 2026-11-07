<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Mail\StaffAlert;
use App\Models\Dispute;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Contestazioni dei pagamenti (charge.dispute.created/updated/closed). Finché la contestazione è aperta i biglietti
 * dell'ordine sono disputed (non validi al check-in); vinta tornano valid, persa diventano cancelled e i posti si
 * liberano. La risposta a Stripe (prove) si dà dalla Dashboard.
 */
class DisputeService
{
    public function __construct(private CapacityService $capacity) {}

    /** Esiti: opened, updated, won, lost, already_closed, unknown_order. */
    public function apply(array $dispute): string
    {
        $order = Order::where('stripe_payment_intent_id', $dispute['payment_intent'] ?? '')->first();
        if (! $order) {
            return 'unknown_order';
        }

        return DB::transaction(function () use ($order, $dispute) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $row = Dispute::where('stripe_dispute_id', $dispute['id'])->lockForUpdate()->first();
            $closed = in_array($dispute['status'], Dispute::CLOSED, true);
            // Chiusa una volta per tutte: un evento arrivato dopo (Stripe non garantisce l'ordine) non cambia nulla.
            if ($row?->closed_at) {
                return 'already_closed';
            }

            $row ??= new Dispute(['stripe_dispute_id' => $dispute['id'], 'order_id' => $order->id]);
            $row->fill([
                'stripe_charge_id' => $dispute['charge'] ?? null, 'amount_cents' => $dispute['amount'],
                'currency' => $dispute['currency'], 'reason' => $dispute['reason'] ?? null, 'status' => $dispute['status'],
                'evidence_due_by' => isset($dispute['evidence_details']['due_by']) ? now()->setTimestamp($dispute['evidence_details']['due_by']) : null,
                'closed_at' => $closed ? ($row->closed_at ?? now()) : null,
            ]);
            $isNew = ! $row->exists;
            $row->save();

            $tickets = $order->tickets();
            $line = "Ordine {$order->id} ({$order->buyer_email}), contestazione {$dispute['id']}: {$dispute['status']}, {$dispute['amount']} centesimi.";

            if (! $closed) {
                $tickets->where('status', TicketStatus::Valid)->update(['status' => TicketStatus::Disputed]);
                if ($isNew) {
                    StaffAlert::raise('Pagamento contestato: biglietti sospesi', [$line, 'Rispondi dalla Dashboard Stripe entro la scadenza.']);
                }

                return $isNew ? 'opened' : 'updated';
            }

            if ($dispute['status'] === 'lost') {
                $tickets->where('status', TicketStatus::Disputed)->update(['status' => TicketStatus::Cancelled, 'cancelled_at' => now()]);
                $this->capacity->releaseConfirmed($order, 'dispute_lost');
                $order->update(['review_reason' => 'dispute_lost']);
                StaffAlert::raise('Contestazione persa: biglietti revocati', [$line]);

                return 'lost';
            }

            $tickets->where('status', TicketStatus::Disputed)->update(['status' => TicketStatus::Valid]);
            StaffAlert::raise('Contestazione chiusa a favore: biglietti riattivati', [$line]);

            return 'won';
        }, 3);
    }
}
