<?php

// Riserva dei posti e avvio del Checkout (docs/architettura.md §6.2, §7.2).

use App\Enums\CapacityHoldStatus;
use App\Enums\OrderStatus;
use App\Models\CapacityPool;
use App\Models\Order;
use App\Models\User;
use App\Services\CapacityService;
use App\Services\CheckoutService;
use App\Services\PaymentService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->checkout = app(CheckoutService::class);
});

it('riserva i posti per 36 minuti con la sessione da 31', function () {
    $order = reserve($this->user, [line(passType(), $this->user)]);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->checkout_expires_at->diffInMinutes(now(), true))->toEqualWithDelta(31, 0.1)
        ->and($order->holds)->toHaveCount(3)
        ->and($order->holds->first()->expires_at->diffInMinutes(now(), true))->toEqualWithDelta(36, 0.1)
        ->and(CapacityPool::sum('held'))->toEqual(3);
});

it('rifiuta una riserva sotto i 30 minuti', function () {
    $order = Order::factory()->create(['event_id' => eas8()->id]);

    expect(fn () => app(CapacityService::class)->hold($order, [], now()->addMinutes(29)))->toThrow(Exception::class, 'hold_too_short');
});

it('rifiuta posti esauriti', function () {
    workshop()->pools()->update(['capacity' => 1]);
    $other = User::factory()->create();
    reserve($other, [line(passType(), $other), line(workshop(), $other)]);

    expect(fn () => reserve($this->user, [line(passType(), $this->user), line(workshop(), $this->user)]))->toThrow(Exception::class, 'sold_out')
        ->and(Order::count())->toBe(1);
});

it('applica il limite per ordine sul pool', function () {
    workshop()->update(['max_per_order' => 4]);
    $people = [$this->user, ['name' => 'B', 'email' => 'b@example.test'], ['name' => 'C', 'email' => 'c@example.test']];

    expect(fn () => reserve($this->user, [line(passType(), ...$people), line(workshop(), ...$people)]))
        ->toThrow(Exception::class, 'too_many_for_pool');
});

it('applica il limite per tipo', function () {
    $people = array_map(fn ($i) => ['name' => "P$i", 'email' => "p$i@example.test"], range(1, 5));

    expect(fn () => reserve($this->user, [line(passType(), ...$people)]))->toThrow(Exception::class, 'too_many_for_type');
});

describe('workshop', function () {
    it('si compra solo con un pass intero', function () {
        expect(fn () => reserve($this->user, [line(workshop(), $this->user)]))->toThrow(Exception::class, 'workshop_needs_pass');
    });

    it('si compra dopo, se il pass è già pagato', function () {
        $first = reserveAndCheckout($this->user, [line(passType(), $this->user)]);
        app(PaymentService::class)->markPaid($first, $this->stripe->pay($first->stripe_checkout_session_id));

        expect(reserve($this->user, [line(workshop(), $this->user)])->total_cents)->toBe(10000);
    });

    it('al massimo 2 per ordine', function () {
        expect(fn () => reserve($this->user, [line(passType(), $this->user), line(workshop(1), $this->user), line(workshop(2), $this->user), line(workshop(3), $this->user)]))
            ->toThrow(Exception::class, 'too_many_workshops');
    });

    it('mai due alla stessa ora', function () {
        workshop(1)->programSession->update(['starts_at' => '2027-04-08 09:00', 'ends_at' => '2027-04-08 11:00']);
        workshop(2)->programSession->update(['starts_at' => '2027-04-08 10:30', 'ends_at' => '2027-04-08 12:00']);
        workshop(3)->programSession->update(['starts_at' => '2027-04-08 11:00', 'ends_at' => '2027-04-08 12:00']);

        expect(fn () => reserve($this->user, [line(passType(), $this->user), line(workshop(1), $this->user), line(workshop(2), $this->user)]))
            ->toThrow(Exception::class, 'workshop_overlap')
            ->and(reserve($this->user, [line(passType(), $this->user), line(workshop(1), $this->user), line(workshop(3), $this->user)])->items)->toHaveCount(3);
    });
});

it('non vende tipi spenti o di un altro canale', function () {
    $companion = eas8()->ticketTypes()->where('kind', 'companion')->first();

    expect(fn () => reserve($this->user, [line($companion, $this->user)]))->toThrow(Exception::class, 'not_available');
});

it('crea la Checkout Session con price_data, solo carta, metadata e Idempotency-Key', function () {
    $order = reserveAndCheckout($this->user, [line(passType(), $this->user)]);
    [, $params, $key] = $this->stripe->callsTo('createCheckoutSession')[0];

    expect($key)->toBe('checkout-'.$order->id)
        ->and($params['mode'])->toBe('payment')
        ->and($params['payment_method_types'])->toBe(['card'])
        ->and($params['allow_promotion_codes'])->toBeFalse()
        ->and($params['line_items'][0]['price_data'])->toMatchArray(['currency' => 'eur', 'unit_amount' => 69500])
        ->and($params['metadata'])->toMatchArray(['order_id' => $order->id, 'kind' => 'tickets', 'price_role' => 'public', 'price_tier' => 'early_bird'])
        ->and($params['payment_intent_data']['metadata']['order_id'])->toBe($order->id)
        ->and($params['client_reference_id'])->toBe($order->id)
        ->and($params['expires_at'])->toBe($order->checkout_expires_at->timestamp)
        ->and($params['success_url'])->toEndWith('?session_id={CHECKOUT_SESSION_ID}')
        ->and($order->stripe_checkout_session_id)->toStartWith('cs_test_');
});

it('allunga la riserva se l\'acquirente ha aspettato prima di pagare', function () {
    $order = reserve($this->user, [line(passType(), $this->user)]);
    $this->travel(20)->minutes();

    $this->checkout->startCheckout($order);

    expect($order->refresh()->checkout_expires_at->diffInMinutes(now(), true))->toEqualWithDelta(31, 0.1)
        ->and($order->holds()->first()->expires_at->diffInMinutes(now(), true))->toEqualWithDelta(36, 0.1);
});

it('con un secondo ordine fa scadere la sessione Stripe del primo e lo chiude', function () {
    $first = reserveAndCheckout($this->user, [line(passType(), $this->user)]);

    $second = reserve($this->user, [line(passType(), $this->user)]);

    expect($this->stripe->callsTo('expireCheckoutSession')[0][1])->toBe($first->stripe_checkout_session_id)
        ->and($first->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($first->holds->pluck('status')->unique()->all())->toBe([CapacityHoldStatus::Released])
        ->and($second->status)->toBe(OrderStatus::Pending)
        ->and(CapacityPool::sum('held'))->toEqual(3);
});

it('se il primo ordine intanto è stato pagato, lo conferma invece di chiuderlo', function () {
    $first = reserveAndCheckout($this->user, [line(passType(), $this->user)]);
    $this->stripe->pay($first->stripe_checkout_session_id);

    reserve($this->user, [line(passType(), $this->user)]);

    expect($first->refresh()->status)->toBe(OrderStatus::Paid)->and($first->tickets)->toHaveCount(1);
});

describe('dal sito', function () {
    it('riserva dal form e chiede la conferma dei nominativi prima di pagare', function () {
        $pass = passType();
        $this->actingAs($this->user)->post('/cart', [
            'lines' => [$pass->id => ['attendees' => [['name' => 'Ana Silva', 'email' => 'ana@example.test'], ['name' => '', 'email' => '']]]],
        ])->assertRedirect();
        $order = $this->user->orders()->sole();

        $this->get("/account/orders/{$order->id}")->assertOk()->assertSee('Ana Silva')->assertSee('cannot be changed');
        $this->post("/orders/{$order->id}/checkout")->assertSessionHasErrors('attendees_confirmed');
        expect($this->stripe->calls)->toBeEmpty();

        $this->post("/orders/{$order->id}/checkout", ['attendees_confirmed' => '1'])
            ->assertRedirect($this->stripe->sessions[$order->refresh()->stripe_checkout_session_id]['url']);
    });

    it('mostra un errore leggibile se i posti sono finiti', function () {
        workshop()->pools()->update(['capacity' => 0]);

        $this->actingAs($this->user)->postJson('/cart', ['lines' => [
            passType()->id => ['attendees' => [['name' => 'A', 'email' => $this->user->email]]],
            workshop()->id => ['attendees' => [['name' => 'A', 'email' => $this->user->email]]],
        ]])->assertStatus(422)->assertJson(['error' => 'sold_out', 'message' => 'Sorry, there are no seats left.']);
    });

    it('chiede di accedere', function () {
        $this->post('/cart')->assertRedirect('/login');
    });
});
