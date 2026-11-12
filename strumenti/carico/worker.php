<?php

/**
 * Un processo della prova di carico (come un processo PHP-FPM): aspetta l'istante di partenza, poi per ogni utente
 * assegnato riserva (pass e a volte workshop), apre il Checkout e conferma il pagamento con gli stessi Service del
 * sito. Stampa una riga JSON per utente. Uso: php worker.php <piano.json> <indice>. Autore: Giulio Occhipinti.
 */

declare(strict_types=1);

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\ShopException;
use App\Services\StripeGateway;
use Illuminate\Database\QueryException;

require __DIR__.'/app.php';

[, $planFile, $index] = $argv;
$plan = json_decode((string) file_get_contents($planFile), true);
$app = loadApp($plan['env']);
$checkout = $app->make(CheckoutService::class);
$payments = $app->make(PaymentService::class);
$stripe = $app->make(StripeGateway::class);
$event = Event::findOrFail($plan['event_id']);
$users = User::whereIn('id', $plan['workers'][(int) $index])->get()->keyBy('id');

/** Esito di un'eccezione: codice del ShopException, o il tipo di errore del database (deadlock, lock wait, altro). */
function outcome(Throwable $e): string
{
    return match (true) {
        $e instanceof ShopException => $e->getMessage(),
        $e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1213 => 'error:deadlock',
        $e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1205 => 'error:lock_wait_timeout',
        default => 'error:'.class_basename($e).': '.mb_substr($e->getMessage(), 0, 160),
    };
}

$ms = fn (float $from) => round((hrtime(true) - $from) / 1e6, 2);
usleep(max(0, (int) (($plan['start_at'] - microtime(true)) * 1e6)));

foreach ($plan['workers'][(int) $index] as $userId) {
    $user = $users[$userId];
    $row = ['user' => $userId, 'workshop' => in_array($userId, $plan['with_workshop'], true)];
    $types = $row['workshop'] ? [$plan['pass_id'], $plan['workshop_id']] : [$plan['pass_id']];
    $lines = array_map(fn ($type) => ['ticket_type_id' => $type, 'attendees' => [['name' => $user->name, 'email' => $user->email]]], $types);

    $t = hrtime(true);
    try {
        $order = $checkout->reserve($user, $event, $lines);
        $row += ['reserve_ms' => $ms($t), 'reserve' => 'reserved'];
    } catch (Throwable $e) {
        echo json_encode($row + ['reserve_ms' => $ms($t), 'reserve' => outcome($e)]), "\n";

        continue;
    }

    $t = hrtime(true);
    try {
        $checkout->startCheckout($order);
        $order->refresh();
        $row['checkout_ms'] = $ms($t);
        $t = hrtime(true);
        $row['pay'] = $payments->markPaid(Order::findOrFail($order->id), $stripe->pay($order->stripe_checkout_session_id));
        $row['pay_ms'] = $ms($t);
    } catch (Throwable $e) {
        $row['pay'] = outcome($e);
    }
    echo json_encode($row), "\n";
}
