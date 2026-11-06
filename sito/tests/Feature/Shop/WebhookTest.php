<?php

// POST /stripe/webhook: firma, livemode, salvataggio una volta sola, elaborazione in coda (§6.6).

use App\Enums\OrderStatus;
use App\Enums\StripeEventStatus;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->order = reserveAndCheckout($this->user, [line(passType(), $this->user)]);
    $this->sessionId = $this->order->stripe_checkout_session_id;
});

it('rifiuta una firma sbagliata senza scrivere nulla', function () {
    Queue::fake();

    postWebhook(stripeEvent('checkout.session.completed', ['id' => 'cs_x']), 'whsec_wrong')->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rifiuta un evento live su un ambiente di test', function () {
    $event = stripeEvent('checkout.session.completed', ['id' => 'cs_x']);
    $event['livemode'] = true;

    postWebhook($event)->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
});

it('salva l\'evento e risponde subito: il lavoro va in coda una volta sola', function () {
    Queue::fake();
    $event = stripeEvent('checkout.session.completed', $this->stripe->pay($this->sessionId));

    postWebhook($event)->assertOk();
    postWebhook($event)->assertOk();   // reinvio di Stripe

    expect(StripeEvent::count())->toBe(1)
        ->and(StripeEvent::find($event['id'])->only('status', 'object_id'))->toEqual(['status' => StripeEventStatus::Received, 'object_id' => $this->sessionId])
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Pending);
    Queue::assertPushed(ProcessStripeEvent::class, 1);
});

it('checkout.session.completed: il job paga l\'ordine una volta sola anche se ripetuto', function () {
    $event = stripeEvent('checkout.session.completed', $this->stripe->pay($this->sessionId));

    postWebhook($event)->assertOk();
    app()->call([new ProcessStripeEvent($event['id']), 'handle']);   // stesso job lanciato di nuovo

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->tickets)->toHaveCount(1)
        ->and(StripeEvent::find($event['id'])->status)->toBe(StripeEventStatus::Processed);
});

it('due eventi diversi per la stessa sessione (webhook e reinvio) danno un solo pagamento', function () {
    $session = $this->stripe->pay($this->sessionId);

    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();
    postWebhook(stripeEvent('checkout.session.async_payment_succeeded', $session))->assertOk();

    expect($this->order->tickets()->count())->toBe(1)->and(StripeEvent::count())->toBe(2);
});

it('checkout.session.expired: chiude l\'ordine e libera i posti', function () {
    postWebhook(stripeEvent('checkout.session.expired', $this->stripe->expireCheckoutSession($this->sessionId)))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($this->order->holds()->where('status', 'released')->count())->toBe(3);
});

it('pagamento asincrono: processing, poi fallito', function () {
    $session = ['status' => 'complete', 'payment_status' => 'unpaid'] + $this->stripe->sessions[$this->sessionId];

    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();
    expect($this->order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($this->order->holds()->first()->status->value)->toBe('awaiting_payment');

    postWebhook(stripeEvent('checkout.session.async_payment_failed', $session))->assertOk();
    expect($this->order->refresh()->status)->toBe(OrderStatus::Failed);
});

it('segna ignored gli eventi che non ci riguardano', function () {
    $event = stripeEvent('customer.created', ['id' => 'cus_x']);

    postWebhook($event)->assertOk();

    expect(StripeEvent::find($event['id'])->status)->toBe(StripeEventStatus::Ignored);
});

it('segna failed con l\'errore e riprova', function () {
    Queue::fake();
    $session = ['id' => 'cs_other'] + $this->stripe->pay($this->sessionId);
    $event = stripeEvent('checkout.session.completed', $session);
    postWebhook($event)->assertOk();

    expect(fn () => app()->call([new ProcessStripeEvent($event['id']), 'handle']))->toThrow(Exception::class, 'session_mismatch')
        ->and(StripeEvent::find($event['id'])->only('status', 'attempts', 'last_error'))
        ->toEqual(['status' => StripeEventStatus::Failed, 'attempts' => 1, 'last_error' => 'session_mismatch']);
});
