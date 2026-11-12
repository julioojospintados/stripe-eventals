<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\StripeGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ogni minuto dallo scheduler: ordini pending con la riserva scaduta. I posti si rilasciano solo dopo aver letto la
 * sessione su Stripe (aperta → expire; pagata → markPaid): job fermo = si vende meno, mai due volte (§7.2).
 */
class ExpireOrders extends Command
{
    protected $signature = 'eventals:expire-orders';

    /** @var list<string> Nome di prima del sistema multi-evento, deprecato: resta per cron e script esistenti. */
    protected $aliases = ['eas:expire-orders'];

    protected $description = 'Chiude gli ordini con la riserva scaduta, dopo averlo chiesto a Stripe';

    public function handle(StripeGateway $stripe, PaymentService $payments): int
    {
        $orders = Order::where('status', OrderStatus::Pending)
            ->where('checkout_expires_at', '<', now()->subMinutes(5))
            ->limit(200)->get();

        foreach ($orders as $order) {
            try {
                if (! $order->stripe_checkout_session_id) {
                    $payments->close($order, OrderStatus::Expired, 'hold_expired');

                    continue;
                }
                $session = $stripe->retrieveCheckoutSession($order->stripe_checkout_session_id);
                if ($session['status'] === 'open') {
                    $session = $stripe->expireCheckoutSession($session['id']);
                }
                $this->line($order->id.': '.$payments->applySession($order, $session));
            } catch (Throwable $e) {
                report($e);  // gli altri ordini vanno avanti; questo si riprova al minuto dopo
            }
        }

        return self::SUCCESS;
    }
}
