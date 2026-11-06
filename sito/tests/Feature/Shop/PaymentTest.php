<?php

// Esito dei pagamenti: conferma, conferma tardiva, rimborso automatico, scadenze dal cron (§6.6, §7.2).

use App\Enums\CapacityHoldStatus;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Enums\TicketStatus;
use App\Jobs\RefundOrderFully;
use App\Jobs\SendTicketsEmail;
use App\Mail\StaffAlert;
use App\Models\CapacityPool;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->payments = app(PaymentService::class);
    $this->user = User::factory()->create();
    workshop()->pools()->update(['capacity' => 1]);
    $this->lines = fn (User $u) => [line(passType(), $u), line(workshop(), $u)];
    $this->wsPool = fn () => workshop()->pools()->first();
});

/** Ordine scaduto dopo il rilascio confermato da Stripe: il posto del workshop torna libero. */
function expiredOrder(User $user, array $lines): Order
{
    $order = reserveAndCheckout($user, $lines);
    test()->stripe->expireCheckoutSession($order->stripe_checkout_session_id);
    app(PaymentService::class)->close($order, OrderStatus::Expired, 'checkout_expired', test()->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id));
    test()->stripe->sessions[$order->stripe_checkout_session_id]['status'] = 'open';  // pagata all'ultimo secondo

    return $order->refresh();
}

it('conferma il pagamento: posti venduti, biglietti, email in coda', function () {
    Queue::fake([SendTicketsEmail::class]);
    $order = reserveAndCheckout($this->user, ($this->lines)($this->user));

    $result = $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    expect($result)->toBe('paid')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toStartWith('pi_test_')
        ->and($order->tickets)->toHaveCount(2)
        ->and(($this->wsPool)()->only('held', 'confirmed'))->toBe(['held' => 0, 'confirmed' => 1]);
    Queue::assertPushed(SendTicketsEmail::class, 1);
});

it('una conferma ripetuta non emette biglietti doppi', function () {
    $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
    $session = $this->stripe->pay($order->stripe_checkout_session_id);

    $results = [$this->payments->markPaid($order, $session), $this->payments->markPaid($order, $session), $this->payments->applySession($order, $session)];

    expect($results)->toBe(['paid', 'already_done', 'already_done'])
        ->and($order->tickets()->count())->toBe(2)
        ->and(($this->wsPool)()->confirmed)->toBe(1);
});

it('rifiuta una sessione Stripe diversa da quella dell\'ordine', function () {
    $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
    $other = reserveAndCheckout(User::factory()->create(), [line(passType(), ['name' => 'X', 'email' => 'x@example.test'])]);

    expect(fn () => $this->payments->markPaid($order, $this->stripe->pay($other->stripe_checkout_session_id)))
        ->toThrow(Exception::class, 'session_mismatch');
});

it('conferma tardiva con posto libero: riprenota (expired → paid)', function () {
    $order = expiredOrder($this->user, ($this->lines)($this->user));
    expect(($this->wsPool)()->held)->toBe(0);

    $result = $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    expect($result)->toBe('paid_late')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->holds->pluck('status')->unique()->all())->toBe([CapacityHoldStatus::Converted])
        ->and($order->tickets)->toHaveCount(2)
        ->and(($this->wsPool)()->confirmed)->toBe(1);
});

it('conferma tardiva senza posti: needs_refund, nessun biglietto, rimborso in coda e avviso admin', function () {
    Queue::fake([RefundOrderFully::class]);
    Mail::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->staff(StaffRole::Admin)->create();

    $order = expiredOrder($this->user, ($this->lines)($this->user));
    $other = User::factory()->create();
    $taken = reserveAndCheckout($other, ($this->lines)($other));
    $this->payments->markPaid($taken, $this->stripe->pay($taken->stripe_checkout_session_id));

    $result = $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    expect($result)->toBe('needs_refund')
        ->and($order->refresh()->status)->toBe(OrderStatus::NeedsRefund)
        ->and($order->tickets)->toHaveCount(0)
        ->and(($this->wsPool)()->only('held', 'confirmed', 'capacity'))->toEqual(['capacity' => 1, 'held' => 0, 'confirmed' => 1]);
    Queue::assertPushed(RefundOrderFully::class, fn ($job) => $job->orderId === $order->id);
    Mail::assertQueued(StaffAlert::class);
});

it('il rimborso automatico usa l\'Idempotency-Key e non si ripete', function () {
    $order = expiredOrder($this->user, ($this->lines)($this->user));
    $other = User::factory()->create();
    $taken = reserveAndCheckout($other, ($this->lines)($other));
    $this->payments->markPaid($taken, $this->stripe->pay($taken->stripe_checkout_session_id));

    $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));  // la coda sync lancia il rimborso
    (new RefundOrderFully($order->id))->handle($this->stripe);

    $refunds = $this->stripe->callsTo('createRefund');
    expect($refunds)->toHaveCount(1)
        ->and($refunds[0][2])->toBe('auto-refund-'.$order->id)
        ->and($refunds[0][1]['payment_intent'])->toBe($order->refresh()->stripe_payment_intent_id)
        ->and($order->refunds()->sole()->only('cause', 'source', 'amount_cents'))->toEqual(['amount_cents' => 79500, 'cause' => 'no_capacity', 'source' => 'auto']);
});

it('charge.refunded chiude il rimborso: ordine refunded, biglietti rimborsati, posti liberati', function () {
    $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
    $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    $result = $this->payments->recordRefund([
        'payment_intent' => $order->refresh()->stripe_payment_intent_id, 'amount_refunded' => 79500, 'refunded' => true,
    ]);

    expect($result)->toBe('refunded')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->tickets->pluck('status')->unique()->all())->toBe([TicketStatus::Refunded])
        ->and($order->refunds()->sole()->needs_review)->toBeTrue()    // nato nella Dashboard
        ->and(($this->wsPool)()->confirmed)->toBe(0);
});

it('chiude solo ordini pending o processing, anche senza trigger', function () {
    $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
    $this->payments->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));

    expect($this->payments->close($order, OrderStatus::Expired, 'test', $this->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id)))->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Expired))->toBeFalse();
});

describe('eas:expire-orders', function () {
    it('non tocca le riserve non ancora scadute', function () {
        $order = reserveAndCheckout($this->user, ($this->lines)($this->user));

        $this->artisan('eas:expire-orders')->assertSuccessful();

        expect($order->refresh()->status)->toBe(OrderStatus::Pending)->and($this->stripe->callsTo('expireCheckoutSession'))->toBeEmpty();
    });

    it('sessione ancora aperta: la fa scadere su Stripe e poi rilascia', function () {
        $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
        $this->travel(37)->minutes();

        $this->artisan('eas:expire-orders')->assertSuccessful();

        expect($this->stripe->callsTo('expireCheckoutSession'))->toHaveCount(1)
            ->and($order->refresh()->status)->toBe(OrderStatus::Expired)
            ->and(CapacityPool::sum('held'))->toEqual(0);
    });

    it('sessione pagata nel frattempo: conferma invece di rilasciare', function () {
        $order = reserveAndCheckout($this->user, ($this->lines)($this->user));
        $this->stripe->pay($order->stripe_checkout_session_id);
        $this->travel(37)->minutes();

        $this->artisan('eas:expire-orders')->assertSuccessful();

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and($order->tickets)->toHaveCount(2);
    });

    it('ordine mai arrivato a Stripe: chiuso senza chiamate', function () {
        $order = reserve($this->user, ($this->lines)($this->user));
        $this->travel(37)->minutes();

        $this->artisan('eas:expire-orders')->assertSuccessful();

        expect($order->refresh()->status)->toBe(OrderStatus::Expired)->and($this->stripe->calls)->toBeEmpty();
    });
});
