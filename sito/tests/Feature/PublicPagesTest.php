<?php

// Pagine pubbliche e area personale: rispondono, mostrano prezzi e posti veri, cambiano lingua.

use App\Models\User;
use App\Services\PaymentService;

beforeEach(fn () => eas8());

it('risponde 200 sulle pagine pubbliche', function (string $url) {
    $this->get($url)->assertOk();
})->with(['/', '/tickets', '/membership', '/login']);

it('mostra fascia attiva, prezzi pubblico e membro e posti dei workshop', function () {
    $this->get('/tickets')->assertOk()
        ->assertSee('Early bird')->assertSee('€695')->assertSee('€276.50')
        ->assertSee('Workshop 1')->assertSee('20 seats left')
        ->assertSee('Log in to buy');
    $this->get('/')->assertSee('EAS8')->assertSee('Mastering Clinical Challenges');
});

it('mostra al membro il suo prezzo e precompila il suo nome', function () {
    $member = member(['name' => 'Marta Reis']);

    $this->actingAs($member)->get('/tickets')->assertOk()
        ->assertSee('You are a member.')->assertSee('value="Marta Reis"', false)
        ->assertSee('Continue: check the names');
});

it('apre area personale, esito del checkout e pagina del codice', function () {
    $user = User::factory()->create();
    $order = reserveAndCheckout($user, [line(passType(), $user)]);

    $this->actingAs($user)->get('/account')->assertOk()->assertSee('Waiting for payment');
    $this->get("/account/orders/{$order->id}")->assertOk()->assertSee('Check the names and pay')->assertSee('I have checked the names.');

    app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));
    $this->get("/account/orders/{$order->id}")->assertOk()->assertSee('Payment received')
        ->assertSee(route('account.tickets.qr', $order->tickets()->sole()->id), false);

    $this->post('/logout');
    $this->withSession(['login_email' => 'a@example.test'])->get('/login/code')->assertOk()->assertSee('a@example.test');
});

it('passa all\'italiano con il selettore e lo ricorda nell\'account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/tickets')->get('/language/it')->assertRedirect('/tickets');
    $this->get('/tickets')->assertSee('Biglietti')->assertSee('lang="it"', false);
    expect($user->refresh()->locale)->toBe('it');

    $this->get('/language/de')->assertNotFound();
});
