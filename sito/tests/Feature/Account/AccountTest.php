<?php

// Area personale: solo i propri ordini e biglietti (IDOR), pagina di successo che conferma da sola (§6.2, §15).

use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\PaymentService;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->order = reserveAndCheckout($this->owner, [line(passType(), $this->owner)]);
    $this->intruder = User::factory()->create();
});

it('la pagina di successo conferma il pagamento senza aspettare il webhook', function () {
    $this->stripe->pay($this->order->stripe_checkout_session_id);

    $this->actingAs($this->owner)->get("/account/orders/{$this->order->id}?session_id={$this->order->stripe_checkout_session_id}")
        ->assertOk()->assertSee('paid')->assertSee($this->order->tickets()->sole()->short_code);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('una session_id che non è dell\'ordine non cambia nulla', function () {
    $other = reserveAndCheckout($this->intruder, [line(passType(), $this->intruder)]);
    $this->stripe->pay($other->stripe_checkout_session_id);

    $this->actingAs($this->owner)->get("/account/orders/{$this->order->id}?session_id={$other->stripe_checkout_session_id}")->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('nega ordini e biglietti di un altro utente (IDOR)', function () {
    app(PaymentService::class)->markPaid($this->order, $this->stripe->pay($this->order->stripe_checkout_session_id));
    $ticket = $this->order->tickets()->sole();
    $this->actingAs($this->intruder);

    $this->get("/account/orders/{$this->order->id}")->assertNotFound();
    $this->get("/account/tickets/{$ticket->id}/qr")->assertNotFound();
    $this->post("/orders/{$this->order->id}/checkout", ['attendees_confirmed' => '1'])->assertNotFound();
    $this->get('/account')->assertOk()->assertDontSee($ticket->short_code);
    $this->get('/account/orders/not-a-uuid')->assertNotFound();
});

it('mostra al proprietario ordini, biglietti e QR', function () {
    app(PaymentService::class)->markPaid($this->order, $this->stripe->pay($this->order->stripe_checkout_session_id));
    $ticket = $this->order->tickets()->sole();

    $this->actingAs($this->owner)->get('/account')->assertOk()->assertSee($ticket->short_code);
    $this->get("/account/tickets/{$ticket->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('mostra il biglietto anche all\'intestatario che non l\'ha comprato', function () {
    $friend = User::factory()->create();
    $order = reserveAndCheckout($this->intruder, [line(passType(), $friend)]);
    app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    $this->actingAs($friend)->get('/account')->assertSee($order->tickets()->sole()->short_code);
    $this->get("/account/orders/{$order->id}")->assertNotFound();
});

it('chiede l\'accesso per l\'area personale', function () {
    $this->get('/account')->assertRedirect('/login');
});
