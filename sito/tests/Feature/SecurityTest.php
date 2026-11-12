<?php

// Correzioni della revisione di sicurezza (docs/tecnico/sicurezza.md): una prova per ciascuna.

use App\Models\StripeEvent;
use App\Models\User;
use App\Services\CsvExports;
use Illuminate\Support\Facades\Mail;

it('rifiuta i webhook se manca STRIPE_WEBHOOK_SECRET: una firma con chiave vuota non vale', function (?string $secret) {
    config(['services.stripe.webhook_secret' => $secret]);

    postWebhook(stripeEvent('checkout.session.completed', ['id' => 'cs_x', 'object' => 'checkout.session']), '')->assertStatus(500);

    expect(StripeEvent::count())->toBe(0);
})->with(['vuoto' => '', 'null' => null]);

it('al massimo 10 codici di accesso al giorno per email, anche cambiando IP', function () {
    Mail::fake();
    foreach (range(1, 10) as $i) {
        if ($i > 1 && $i % 3 === 1) {
            $this->travel(11)->minutes();   // oltre il limite di 3 ogni 10 minuti
        }
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
            ->post('/login', ['email' => 'ana@example.test'])->assertSessionHasNoErrors();
    }
    $this->travel(11)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/login', ['email' => 'ana@example.test'])->assertSessionHasErrors('email');
});

it('neutralizza nei CSV anche le celle che iniziano con tab o a capo', function (string $value) {
    $safe = (fn ($v) => $this->safe($v))->call(app(CsvExports::class), $value);

    expect($safe)->toBe("'".$value);
})->with(['tab' => "\t=1+1", 'a capo' => "\r=1+1", 'uguale' => '=1+1', 'chiocciola' => '@SUM(A1)']);

it('limita le chiamate a Stripe della membership: 10 al minuto per utente', function () {
    $user = User::factory()->create();
    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->post('/membership/portal')->assertNotFound();   // senza cliente Stripe
    }

    $this->actingAs($user)->post('/membership/portal')->assertTooManyRequests();
});

it('mette gli header di sicurezza anche su Apache (public/.htaccess)', function () {
    $htaccess = file_get_contents(public_path('.htaccess'));

    foreach (['X-Content-Type-Options "nosniff"', 'X-Frame-Options "SAMEORIGIN"', 'Referrer-Policy', 'Strict-Transport-Security'] as $header) {
        expect($htaccess)->toContain("Header always set $header");
    }
});
