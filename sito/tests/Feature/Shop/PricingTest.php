<?php

// Prezzi calcolati dal server: fasce, ruolo, prezzo membro una volta, codici sconto (docs/architettura.md §6.3, §6.4).

use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Models\DiscountCode;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\PriceResolver;

describe('PriceResolver', function () {
    it('dà il prezzo giusto per fascia e ruolo', function (string $at, string $kind, PriceRole $role, int $cents) {
        $this->travelTo($at);
        $type = $kind === 'pass' ? passType() : workshop();
        $resolver = app(PriceResolver::class);

        expect($resolver->resolve($type, $role, $resolver->activeTier(eas8(), now()))->amount_cents)->toBe($cents);
    })->with([
        'membro in early bird' => ['2026-12-01 10:00', 'pass', PriceRole::Member, 27650],
        'pubblico in early bird' => ['2026-12-01 10:00', 'pass', PriceRole::Public, 69500],
        'membro in regular' => ['2027-03-01 10:00', 'pass', PriceRole::Member, 39500],
        'pubblico in regular' => ['2027-03-01 10:00', 'pass', PriceRole::Public, 69500],
        'workshop membro (nessun prezzo membro)' => ['2026-12-01 10:00', 'workshop', PriceRole::Member, 10000],
    ]);

    it('ignora la fascia spenta (last minute) e usa il prezzo base', function () {
        $this->travelTo('2027-04-05 10:00');

        expect(app(PriceResolver::class)->activeTier(eas8(), now()))->toBeNull();
    });
});

describe('ordine', function () {
    it('fissa ruolo, fascia e importi in centesimi alla riserva', function () {
        $member = member();
        $order = reserve($member, [line(passType(), $member, ['name' => 'Amica', 'email' => 'amica@example.test'])]);

        expect($order->price_role)->toBe(PriceRole::Member)
            ->and($order->priceTier->code)->toBe('early_bird')
            ->and($order->items->pluck('unit_amount_cents', 'price_role.value')->all())->toBe(['member' => 27650, 'public' => 69500])
            ->and($order->total_cents)->toBe(27650 + 69500);
    });

    it('non cambia il prezzo se la fascia finisce durante il pagamento', function () {
        $member = member();
        $order = reserveAndCheckout($member, [line(passType(), $member)]);

        $this->travelTo('2027-02-01 00:10');   // early bird finita
        $result = app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

        expect($result)->toBe('paid')
            ->and($order->refresh()->total_cents)->toBe(27650)
            ->and($order->priceTier->code)->toBe('early_bird')
            ->and($order->tickets()->first()->price_role)->toBe(PriceRole::Member);
    });

    it('è membro solo se la membership copre tutta la sessione di pagamento', function () {
        $user = member();
        $user->memberships()->update(['current_period_end' => now()->addMinutes(10)]);

        expect(reserve($user, [line(passType(), $user)])->price_role)->toBe(PriceRole::Public);
    });

    it('dà il prezzo membro una volta per evento e tipo, anche su due ordini', function () {
        $member = member();
        $first = reserveAndCheckout($member, [line(passType(), $member)]);
        app(PaymentService::class)->markPaid($first, $this->stripe->pay($first->stripe_checkout_session_id));

        $second = reserve($member, [line(passType(), $member)]);

        expect($first->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($second->items->sole()->price_role)->toBe(PriceRole::Public)
            ->and($second->total_cents)->toBe(69500)
            ->and($member->refresh()->isMemberUntil(now()))->toBeTrue();
    });

    it('dà il prezzo membro solo al biglietto intestato al membro che compra', function () {
        $member = member();
        $order = reserve($member, [line(passType(), ['name' => 'Altro', 'email' => 'altro@example.test'])]);

        expect($order->items->sole()->price_role)->toBe(PriceRole::Public);
    });
});

describe('codici sconto', function () {
    beforeEach(fn () => $this->event = eas8());

    function code(array $attributes = []): DiscountCode
    {
        return DiscountCode::factory()->create(['event_id' => eas8()->id] + $attributes);
    }

    it('sconta riga per riga il prezzo pubblico', function () {
        code(['code' => 'TENOFF', 'percent_off' => 10]);
        $user = User::factory()->create();

        $order = reserve($user, [line(passType(), $user)], 'tenoff');

        expect($order->items->sole()->unit_discount_cents)->toBe(6950)
            ->and($order->discount_cents)->toBe(6950)
            ->and($order->total_cents)->toBe(62550);
    });

    it('sulla riga del membro sceglie il migliore tra prezzo membro e codice, mai entrambi', function (int $percent, PriceRole $role, int $unit) {
        code(['code' => 'BIG', 'percent_off' => $percent]);
        $member = member();

        $item = reserve($member, [line(passType(), $member)], 'BIG')->items->sole();

        expect($item->price_role)->toBe($role)->and($item->unit_amount_cents)->toBe($unit);
    })->with([
        'codice 10%: vince il prezzo membro' => [10, PriceRole::Member, 27650],
        'codice 70%: vince il pubblico scontato' => [70, PriceRole::Public, 20850],
    ]);

    it('somma codice e prezzo membro solo se il codice è combinabile', function () {
        code(['code' => 'COMBO', 'percent_off' => 10, 'combinable_with_role_price' => true]);
        $member = member();

        $item = reserve($member, [line(passType(), $member)], 'COMBO')->items->sole();

        expect($item->price_role)->toBe(PriceRole::Member)->and($item->unit_amount_cents)->toBe(27650 - 2765);
    });

    it('conta l\'utilizzo alla riserva e lo restituisce alla scadenza', function () {
        $code = code(['code' => 'ONCE', 'max_redemptions' => 1]);
        [$a, $b] = User::factory()->count(2)->create();

        $order = reserveAndCheckout($a, [line(passType(), $a)], 'ONCE');
        expect($code->refresh()->redeemed_count)->toBe(1)
            ->and(fn () => reserve($b, [line(passType(), $b)], 'ONCE'))->toThrow(Exception::class, 'discount_exhausted');

        app(PaymentService::class)->close($order, OrderStatus::Expired, 'checkout_expired', $this->stripe->expireCheckoutSession($order->stripe_checkout_session_id));

        expect($code->refresh()->redeemed_count)->toBe(0)
            ->and(reserve($b, [line(passType(), $b)], 'ONCE')->discount_cents)->toBe(6950);
    });

    it('rispetta il limite per persona', function () {
        code(['code' => 'MINE', 'per_user_limit' => 1]);
        $user = User::factory()->create();
        $order = reserveAndCheckout($user, [line(passType(), $user)], 'MINE');
        app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

        expect(fn () => reserve($user, [line(passType(), $user)], 'MINE'))->toThrow(Exception::class, 'discount_user_limit');
    });

    it('rifiuta un codice scaduto o di un altro evento', function () {
        code(['code' => 'OLD', 'valid_to' => now()->subDay(), 'valid_from' => now()->subMonth()]);
        $user = User::factory()->create();

        expect(fn () => reserve($user, [line(passType(), $user)], 'OLD'))->toThrow(Exception::class, 'discount_invalid')
            ->and(fn () => reserve($user, [line(passType(), $user)], 'NOPE'))->toThrow(Exception::class, 'discount_invalid');
    });

    it('conferma senza Stripe un ordine a costo zero', function () {
        code(['code' => 'FREE', 'percent_off' => 100]);
        $user = User::factory()->create();
        $order = reserve($user, [line(passType(), $user)], 'FREE');

        app(CheckoutService::class)->startCheckout($order);

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->tickets)->toHaveCount(1)
            ->and($this->stripe->callsTo('createCheckoutSession'))->toBeEmpty();
    });
});
