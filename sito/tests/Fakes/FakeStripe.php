<?php

namespace Tests\Fakes;

use App\Services\StripeGateway;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stripe finto per i test: tiene le sessioni in memoria, registra le chiamate con la loro Idempotency-Key e ripete la
 * risposta per la stessa chiave, come Stripe. Nessuna chiamata di rete.
 */
class FakeStripe extends StripeGateway
{
    public bool $live = false;

    /** Stato del rimborso restituito da createRefund (Stripe può rispondere pending). */
    public string $refundStatus = 'succeeded';

    /** @var array<string, array> */
    public array $sessions = [];

    /** @var array<string, array> */
    public array $subscriptions = [];

    /** @var array<string, array> rimborsi creati, per id */
    public array $refunds = [];

    /** @var list<array{0: string, 1: mixed, 2?: string}> */
    public array $calls = [];

    private array $byKey = [];

    public function livemode(): bool
    {
        return $this->live;
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $this->calls[] = ['createCheckoutSession', $params, $idempotencyKey];
        if (isset($this->byKey[$idempotencyKey])) {
            return $this->sessions[$this->byKey[$idempotencyKey]];
        }

        $id = 'cs_test_'.Str::random(16);
        $this->byKey[$idempotencyKey] = $id;

        return $this->sessions[$id] = [
            'id' => $id, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.test/$id",
            'mode' => $params['mode'], 'status' => 'open', 'payment_status' => 'unpaid',
            'client_reference_id' => $params['client_reference_id'] ?? null, 'metadata' => $params['metadata'] ?? [],
            'amount_total' => collect($params['line_items'])->sum(fn ($l) => ($l['price_data']['unit_amount'] ?? 20000) * $l['quantity']),
            'payment_intent' => null, 'customer' => $params['customer'] ?? null, 'subscription' => null,
            'invoice_creation' => $params['invoice_creation'] ?? null, 'invoice' => null, 'created' => now()->timestamp,
        ];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->sessions[$id] ?? throw new RuntimeException("No such checkout.session: $id");
    }

    public function expireCheckoutSession(string $id): array
    {
        $this->calls[] = ['expireCheckoutSession', $id];
        if ($this->retrieveCheckoutSession($id)['status'] === 'open') {
            $this->sessions[$id]['status'] = 'expired';
        }

        return $this->sessions[$id];
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        $this->calls[] = ['createRefund', $params, $idempotencyKey];

        if (! isset($this->byKey[$idempotencyKey])) {
            $id = 're_test_'.Str::random(16);
            $this->byKey[$idempotencyKey] = $id;
            $this->refunds[$id] = ['id' => $id, 'object' => 'refund', 'status' => $this->refundStatus, 'amount' => $params['amount'],
                'payment_intent' => $params['payment_intent'], 'created' => now()->timestamp];
        }

        return $this->refunds[$this->byKey[$idempotencyKey]];
    }

    public function listCheckoutSessions(int $since): array
    {
        return array_values(array_filter($this->sessions, fn ($s) => $s['created'] >= $since));
    }

    public function listRefunds(int $since): array
    {
        return array_values(array_filter($this->refunds, fn ($r) => $r['created'] >= $since));
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->subscriptions[$id] ?? throw new RuntimeException("No such subscription: $id");
    }

    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        $this->calls[] = ['createPortalSession', $customerId, $returnUrl];

        return ['url' => "https://billing.stripe.test/$customerId"];
    }

    /** Il cliente paga sulla pagina Stripe. */
    public function pay(string $id): array
    {
        $session = $this->retrieveCheckoutSession($id);
        $this->sessions[$id] = array_merge($session, ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_'.Str::random(16),
            'invoice' => ($session['invoice_creation']['enabled'] ?? false) ? 'in_test_'.Str::random(14) : null]);

        if ($session['mode'] === 'subscription') {
            $sub = 'sub_test_'.Str::random(14);
            $this->sessions[$id]['subscription'] = $sub;
            $this->sessions[$id]['customer'] ??= 'cus_test_'.Str::random(14);
            $this->subscriptions[$sub] = [
                'id' => $sub, 'object' => 'subscription', 'status' => 'active', 'customer' => $this->sessions[$id]['customer'],
                'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null,
                'metadata' => ['user_id' => $session['client_reference_id']],
                'items' => ['data' => [['current_period_start' => now()->timestamp, 'current_period_end' => now()->addYear()->timestamp]]],
            ];
        }

        return $this->sessions[$id];
    }

    /** @return list<array> le chiamate di un metodo */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }
}
