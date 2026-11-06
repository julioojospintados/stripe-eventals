<?php

// QA indipendente, Fase 1: pagamenti tardivi fino in fondo (rimborso automatico, prezzo membro ripreso).

use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Enums\StaffRole;
use App\Enums\TicketStatus;
use App\Mail\StaffAlert;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->staff(StaffRole::Admin)->create();   // destinatario degli avvisi
    $this->payments = app(PaymentService::class);
});

/** Ordine scaduto e rilasciato, poi pagato all'ultimo secondo sulla pagina Stripe. */
function lateExpired(User $user, array $lines): Order
{
    $order = reserveAndCheckout($user, $lines);
    test()->stripe->expireCheckoutSession($order->stripe_checkout_session_id);
    app(PaymentService::class)->close($order, OrderStatus::Expired, 'checkout_expired', test()->stripe->retrieveCheckoutSession($order->stripe_checkout_session_id));
    test()->stripe->sessions[$order->stripe_checkout_session_id]['status'] = 'open';

    return $order->refresh();
}

it('pagamento tardivo senza posti: rimborso automatico, poi charge.refunded chiude tutto senza doppi rilasci', function () {
    Mail::fake();
    workshop()->pools()->update(['capacity' => 1]);
    $lines = fn (User $u) => [line(passType(), $u), line(workshop(), $u)];
    [$a, $b] = User::factory()->count(2)->create();

    $late = lateExpired($a, $lines($a));
    $taken = reserveAndCheckout($b, $lines($b));
    $this->payments->markPaid($taken, $this->stripe->pay($taken->stripe_checkout_session_id));

    $this->stripe->refundStatus = 'pending';
    expect($this->payments->markPaid($late, $this->stripe->pay($late->stripe_checkout_session_id)))->toBe('needs_refund');   // la coda sync lancia il rimborso
    expect($late->refresh()->refunds()->sole()->status)->toBe('pending');

    $pools = fn () => workshop()->pools()->first()->only('held', 'confirmed');
    $before = $pools();
    $charge = ['id' => 'ch_late', 'object' => 'charge', 'payment_intent' => $late->stripe_payment_intent_id,
        'amount' => $late->total_cents, 'amount_refunded' => $late->total_cents, 'refunded' => true];
    postWebhook(stripeEvent('charge.refunded', $charge))->assertOk();
    postWebhook(stripeEvent('charge.refunded', $charge))->assertOk();   // evento diverso, stesso stato

    $late->refresh();
    expect($late->status)->toBe(OrderStatus::Refunded)
        ->and($late->refunds()->sole()->only('status', 'needs_review', 'source'))->toEqual(['status' => 'succeeded', 'needs_review' => false, 'source' => 'auto'])
        ->and($late->tickets)->toHaveCount(0)
        ->and($pools())->toEqual($before)
        ->and($before['confirmed'])->toBe(1)
        ->and($taken->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($taken->tickets()->where('status', TicketStatus::Valid)->count())->toBe(2)
        ->and($this->stripe->callsTo('createRefund'))->toHaveCount(1);
});

it('prezzo membro ripreso dopo un pagamento tardivo: il biglietto vale e lo staff è avvisato', function () {
    Mail::fake();
    $member = member();

    $a = lateExpired($member, [line(passType(), $member)]);
    expect($a->price_role)->toBe(PriceRole::Member);
    $b = reserveAndCheckout($member, [line(passType(), $member)]);
    expect($b->price_role)->toBe(PriceRole::Member);
    expect($this->payments->markPaid($b, $this->stripe->pay($b->stripe_checkout_session_id)))->toBe('paid');

    expect($this->payments->markPaid($a, $this->stripe->pay($a->stripe_checkout_session_id)))->toBe('paid_late');

    expect($a->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($a->tickets()->where('status', TicketStatus::Valid)->count())->toBe(1)
        ->and($b->refresh()->tickets()->where('status', TicketStatus::Valid)->count())->toBe(1)
        ->and(DB::table('member_price_claims')->where('user_id', $member->id)->whereNull('released_at')->count())->toBe(1);
    Mail::assertQueued(StaffAlert::class, fn ($m) => $m->title === 'member_price_limit_late');
});
