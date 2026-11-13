<?php

// Un processo separato per le prove in parallelo (tests/Concurrency): avvia l'app sul database di test, aspetta
// l'istante di partenza e lancia uno scenario dentro una transazione che tiene i lock 0,8 secondi, così l'altro
// processo deve aspettare. Stampa l'esito. Uso: php worker.php <scenario> <partenza unix float> <argomenti...>

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\ShopException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $scenario, $startAt] = $argv;
$args = array_slice($argv, 3);
usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1e6)));

$holdLocks = function (callable $work) {
    return DB::transaction(function () use ($work) {
        $result = $work();
        usleep(800000);

        return $result;
    });
};

try {
    echo match ($scenario) {
        // Riserva dal carrello: pass + workshop per l'utente $args[0].
        'reserve' => $holdLocks(function () use ($args) {
            $user = User::findOrFail($args[0]);
            $lines = array_map(fn ($type) => ['ticket_type_id' => $type, 'attendees' => [['name' => $user->name, 'email' => $user->email]]], array_slice($args, 2));
            app(CheckoutService::class)->reserve($user, Event::findOrFail($args[1]), $lines);

            return 'reserved';
        }),
        // Prenotazione del prezzo membro sulla riga $args[0].
        'claim' => $holdLocks(function () use ($args) {
            $item = OrderItem::with('order.user', 'ticketType')->findOrFail($args[0]);
            app(CheckoutService::class)->claimMemberPrice($item, $item->order->user, $item->ticketType);

            return 'claimed';
        }),
        // Conferma del pagamento (webhook, pagina di successo o reinvio) con la sessione pagata.
        'pay' => $holdLocks(function () use ($args) {
            $order = Order::findOrFail($args[0]);

            return app(PaymentService::class)->markPaid($order, [
                'id' => $order->stripe_checkout_session_id, 'status' => 'complete', 'payment_status' => 'paid',
                'payment_intent' => 'pi_test_parallel', 'amount_total' => $order->total_cents, 'currency' => $order->currency,
            ]);
        }),
    };
} catch (ShopException $e) {
    echo $e->getMessage();
}
