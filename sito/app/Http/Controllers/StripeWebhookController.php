<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use App\Services\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * POST /stripe/webhook: verifica la firma, salva l'evento una volta sola e risponde subito; il lavoro lo fa il job
 * ProcessStripeEvent dalla coda (docs/architettura.md §6.6). Escluso dalla protezione CSRF: è firmato.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, StripeGateway $stripe)
    {
        // Con il segreto vuoto chiunque calcolerebbe una firma valida (HMAC con chiave vuota): si rifiuta tutto.
        if (blank(config('services.stripe.webhook_secret'))) {
            report(new \RuntimeException('STRIPE_WEBHOOK_SECRET mancante: webhook rifiutato.'));

            return response('webhook not configured', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(), (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('invalid signature', 400);
        }

        if ($event->livemode !== $stripe->livemode()) {
            return response('wrong mode', 400);
        }

        DB::transaction(function () use ($event) {
            $inserted = StripeEvent::insertOrIgnore([
                'id' => $event->id,
                'type' => $event->type,
                'livemode' => $event->livemode,
                'api_version' => $event->api_version,
                'stripe_created_at' => Carbon::createFromTimestamp($event->created),
                'object_id' => $event->data->object->id ?? null,
                'payload' => json_encode($event->toArray()),
            ]);
            // La riga del job entra nella stessa transazione (coda database): nessun evento perso. Un reinvio non fa nulla.
            if ($inserted === 1) {
                ProcessStripeEvent::dispatch($event->id);
            }
        });

        return response('ok');
    }
}
