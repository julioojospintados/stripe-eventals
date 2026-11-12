<?php

/**
 * Avvio comune di carico.php e worker.php: l'app di sito/ su un database usa e getta, Stripe finto, posta e cache in
 * memoria, coda nel database (come in produzione). Autore: Giulio Occhipinti.
 */

declare(strict_types=1);

use App\Services\StripeGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Fakes\FakeStripe;

require_once dirname(__DIR__, 2).'/sito/vendor/autoload.php';   // serve composer install con le dipendenze dev (Tests\Fakes)

/** Stripe finto senza stato condiviso tra processi: una sessione creata da un altro processo risulta scaduta e non pagata. */
class LoadStripe extends FakeStripe
{
    public function retrieveCheckoutSession(string $id): array
    {
        return $this->sessions[$id] ?? ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'expired',
            'payment_status' => 'unpaid', 'amount_total' => 0, 'payment_intent' => null, 'invoice' => null, 'url' => null];
    }

    public function expireCheckoutSession(string $id): array
    {
        return isset($this->sessions[$id]) ? parent::expireCheckoutSession($id) : $this->retrieveCheckoutSession($id);
    }
}

/** @param array<string, string> $env */
function loadApp(array $env): Application
{
    $defaults = [
        'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'LOG_CHANNEL' => 'null',
    ];
    foreach ($env + $defaults as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    $app = require dirname(__DIR__, 2).'/sito/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app->instance(StripeGateway::class, new LoadStripe);

    $db = (string) config('database.connections.'.config('database.default').'.database');
    if (! preg_match('/_(carico|test)$/', $db)) {
        fwrite(STDERR, "Il database «{$db}» non finisce con _carico o _test: lo script lo svuota, mi fermo.\n");
        exit(2);
    }

    return $app;
}
