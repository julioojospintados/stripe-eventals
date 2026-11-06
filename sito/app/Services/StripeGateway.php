<?php

namespace App\Services;

use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

/**
 * Le sole chiamate a Stripe dell'applicazione, con risposte come array. Nei test il container la sostituisce con
 * Tests\Fakes\FakeStripe: nessuna chiamata vera.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    /** true con una chiave live: un webhook con livemode diverso viene rifiutato. */
    public function livemode(): bool
    {
        return preg_match('/^(sk|rk)_live_/', (string) config('services.stripe.secret')) === 1;
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id)->toArray();
    }

    /** Chiude una sessione ancora aperta; se intanto è stata pagata, Stripe rifiuta e si rilegge la sessione. */
    public function expireCheckoutSession(string $id): array
    {
        try {
            return $this->client()->checkout->sessions->expire($id)->toArray();
        } catch (InvalidRequestException) {
            return $this->retrieveCheckoutSession($id);
        }
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->client()->subscriptions->retrieve($id)->toArray();
    }

    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        return $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ])->toArray();
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient([
            'api_key' => config('services.stripe.secret'),
            'stripe_version' => config('services.stripe.api_version') ?: ApiVersion::CURRENT,
        ]);
    }
}
