<?php

// Biglietti: QR firmato con versione, email in coda con limite di invio, eas:resend-ticket (§9, §11).

use App\Jobs\SendTicketsEmail;
use App\Mail\TicketsMail;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TicketService;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->buyer = User::factory()->create();
    $this->friend = ['name' => 'Bea Costa', 'email' => 'bea@example.test'];
    $this->paid = function () {
        $order = reserveAndCheckout($this->buyer, [line(passType(), $this->buyer, $this->friend)]);
        app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

        return $order->refresh();
    };
});

it('firma il QR con ticket, versione, evento e chiave, senza il nome', function () {
    Mail::fake();
    $ticket = ($this->paid)()->tickets()->first();
    $service = app(TicketService::class);
    $payload = $service->qrPayload($ticket);

    expect($service->verify($payload))->toBe(['t' => $ticket->id, 'v' => 1, 'e' => $ticket->event_id, 'k' => 'k1'])
        ->and($payload)->not->toContain($ticket->attendee_name);

    [$prefix, $message, $signature] = explode('.', $payload);
    $forged = rtrim(strtr(base64_encode(json_encode(['t' => $ticket->id, 'v' => 2, 'e' => $ticket->event_id, 'k' => 'k1'])), '+/', '-_'), '=');
    expect($service->verify("$prefix.$forged.$signature"))->toBeNull();
});

it('verifica i QR firmati con la chiave precedente dopo la rotazione e rifiuta le chiavi sconosciute', function () {
    Mail::fake();
    $ticket = ($this->paid)()->tickets()->first();
    $service = app(TicketService::class);
    $old = $service->qrPayload($ticket);
    $oldPublic = base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(config('tickets.qr.signing_private_key'))));

    config([
        'tickets.qr.signing_private_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
        'tickets.qr.signing_key_id' => 'k2',
        'tickets.qr.previous_public_keys' => "k1:$oldPublic",
    ]);
    expect($service->verify($old)['k'])->toBe('k1')
        ->and($service->verify($service->qrPayload($ticket))['k'])->toBe('k2');

    config(['tickets.qr.previous_public_keys' => null]);
    expect($service->verify($old))->toBeNull();
});

it('manda all\'acquirente tutti i biglietti e a ogni partecipante il suo, una volta sola', function () {
    Mail::fake();
    ($this->paid)();

    Mail::assertSent(TicketsMail::class, 2);
    Mail::assertSent(TicketsMail::class, fn ($m) => $m->hasTo($this->buyer->email) && $m->tickets->count() === 2);
    Mail::assertSent(TicketsMail::class, fn ($m) => $m->hasTo('bea@example.test') && $m->tickets->count() === 1);
    expect(DB::table('sent_messages')->count())->toBe(2);

    $job = new SendTicketsEmail($this->buyer->email, [], DB::table('sent_messages')->value('dedupe_key'));
    $job->handle();
    Mail::assertSent(TicketsMail::class, 2);
});

it('incorpora il QR in PNG nell\'email', function () {
    $order = ($this->paid)();
    $html = (new TicketsMail($order->tickets()->with('ticketType', 'order')->get()))->render();

    expect($html)->toContain('image/png')->toContain($order->tickets()->first()->short_code);
});

it('mette le email in coda con il limite di invio', function () {
    expect((new SendTicketsEmail('a@example.test', [], 'k'))->middleware()[0])->toBeInstanceOf(RateLimited::class);
});

it('eas:resend-ticket rimanda il biglietto in coda', function () {
    Mail::fake();
    $ticket = ($this->paid)()->tickets()->where('attendee_email', 'bea@example.test')->sole();
    Queue::fake();

    $this->artisan('eas:resend-ticket', ['ticket' => strtolower($ticket->short_code)])->assertSuccessful();
    $this->artisan('eas:resend-ticket', ['ticket' => 'ZZZZZZZZ'])->assertFailed();

    Queue::assertPushed(SendTicketsEmail::class, fn ($job) => $job->to === 'bea@example.test' && $job->ticketIds === [$ticket->id]);
});
