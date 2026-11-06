<?php

namespace Tests;

use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeStripe;

abstract class TestCase extends BaseTestCase
{
    public FakeStripe $stripe;

    private static ?string $qrKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Stripe finto per tutti i test, chiavi solo di prova.
        $this->stripe = new FakeStripe;
        $this->app->instance(StripeGateway::class, $this->stripe);
        config([
            'services.stripe.webhook_secret' => 'whsec_test_secret',
            'services.stripe.membership_price' => 'price_test_membership',
            'tickets.qr.signing_private_key' => self::$qrKey ??= base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
        ]);
    }
}
