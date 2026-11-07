<?php

namespace App\Console\Commands;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\StripeEventStatus;
use App\Jobs\ProcessStripeEvent;
use App\Mail\StaffAlert;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Refund;
use App\Models\StripeEvent;
use App\Services\StripeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ogni notte dallo scheduler (o dal pannello, in coda): confronta con Stripe sessioni, pagamenti e rimborsi delle
 * ultime ore e gli ordini locali. Pagato su Stripe ma non qui → rielaborato dallo stesso job del webhook; pagato qui
 * ma non su Stripe e rimborsi non registrati → solo segnalati. Rapporto nel registro audit, avviso se ci sono differenze.
 */
class ReconcileStripe extends Command
{
    protected $signature = 'eas:reconcile {--hours=48 : finestra da controllare}';

    protected $description = 'Confronta ordini e rimborsi con Stripe e rielabora i pagamenti mancanti';

    public function handle(StripeGateway $stripe): int
    {
        $since = now()->subHours((int) $this->option('hours'));
        // Un'ora in più: la sessione nasce fino a 36 minuti prima del pagamento.
        $sessions = collect($stripe->listCheckoutSessions($since->copy()->subHour()->timestamp))
            ->where('mode', 'payment')->keyBy('id');
        $differences = [];

        foreach ($sessions->where('status', 'complete')->where('payment_status', 'paid') as $session) {
            $order = Order::find($session['metadata']['order_id'] ?? null);
            if (! $order || in_array($order->status, OrderStatus::moneyTaken(), true)) {
                continue;   // ordine di un altro ambiente, o già a posto
            }
            $differences[] = ['type' => 'paid_on_stripe_only', 'order' => $order->id, 'session' => $session['id'], 'status' => $order->status->value];
            $this->reprocess($session, $stripe->livemode());
        }

        $paidHere = Order::where('origin', OrderOrigin::Checkout)->where('total_cents', '>', 0)
            ->where('paid_at', '>=', $since)->whereIn('status', OrderStatus::moneyTaken())->get();
        foreach ($paidHere as $order) {
            if (($sessions[$order->stripe_checkout_session_id]['payment_status'] ?? null) !== 'paid') {
                $differences[] = ['type' => 'paid_here_only', 'order' => $order->id, 'session' => $order->stripe_checkout_session_id];
            }
        }

        $refunds = collect($stripe->listRefunds($since->timestamp))->whereNotIn('status', Refund::NOT_MONEY);
        foreach ($refunds->groupBy('payment_intent') as $paymentIntent => $rows) {
            $order = Order::where('stripe_payment_intent_id', $paymentIntent)->first();
            if (! $order) {
                continue;
            }
            $local = (int) $order->refunds()->whereNotIn('status', Refund::NOT_MONEY)->sum('amount_cents');
            if ($rows->sum('amount') > $local) {
                $differences[] = ['type' => 'refund_not_recorded', 'order' => $order->id, 'stripe' => $rows->sum('amount'), 'local' => $local];
            }
        }

        AuditLog::record('stripe.reconciled', null, null, [
            'since' => $since->toIso8601String(), 'sessions' => $sessions->count(), 'paid_orders' => $paidHere->count(),
            'refunds' => $refunds->count(), 'differences' => $differences,
        ]);
        if ($differences) {
            StaffAlert::raise('Riconciliazione Stripe: '.count($differences).' differenze', array_map(fn ($d) => json_encode($d), $differences));
        }

        $this->info(count($differences).' differenze.');
        foreach ($differences as $d) {
            $this->line(json_encode($d));
        }

        return self::SUCCESS;
    }

    /**
     * Stesso percorso del webhook: un evento in stripe_events (id fisso per sessione, quindi una volta sola) e il job;
     * rimesso in coda se è rimasto failed.
     */
    private function reprocess(array $session, bool $livemode): void
    {
        $id = 'evt_reconcile_'.$session['id'];
        DB::transaction(function () use ($id, $session, $livemode) {
            $inserted = StripeEvent::insertOrIgnore([
                'id' => $id, 'type' => 'checkout.session.completed', 'livemode' => $session['livemode'] ?? $livemode,
                'stripe_created_at' => now(), 'object_id' => $session['id'],
                'payload' => json_encode(['id' => $id, 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]),
            ]);
            // Già presente ma failed (tentativi esauriti): si rimette in coda, il job esce da solo se nel frattempo è processed.
            if ($inserted === 1 || StripeEvent::whereKey($id)->where('status', StripeEventStatus::Failed)->exists()) {
                ProcessStripeEvent::dispatch($id);
            }
        });
    }
}
