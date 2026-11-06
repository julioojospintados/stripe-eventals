<?php

// QA indipendente, Fase 1: casi limite su soldi, posti e permessi non coperti dalle altre suite.

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Enums\StripeEventStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Jobs\ProcessStripeEvent;
use App\Jobs\RefundOrderFully;
use App\Mail\StaffAlert;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ShopException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->staff(StaffRole::Admin)->create();   // destinatario degli avvisi
    $this->payments = app(PaymentService::class);
});

function qaPaid(User $buyer): Order
{
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    app(PaymentService::class)->markPaid($order, test()->stripe->pay($order->stripe_checkout_session_id));

    return $order->refresh();
}

function qaCharge(Order $order, int $refunded): array
{
    return ['id' => 'ch_'.Str::random(10), 'object' => 'charge', 'payment_intent' => $order->stripe_payment_intent_id,
        'amount' => $order->total_cents, 'amount_refunded' => $refunded, 'refunded' => $refunded >= $order->total_cents];
}

function qaWebhook(string $type, array $object): string
{
    $event = stripeEvent($type, $object);
    postWebhook($event)->assertOk();

    return $event['id'];
}

it('codice esaurito: il secondo carrello è rifiutato, il codice torna libero quando il primo ordine scade', function () {
    $code = DiscountCode::factory()->create(['code' => 'ONE', 'max_redemptions' => 1]);
    [$a, $b] = User::factory()->count(2)->create();

    $first = reserveAndCheckout($a, [line(passType(), $a)], 'ONE');
    expect(fn () => reserve($b, [line(passType(), $b)], 'ONE'))->toThrow(ShopException::class, 'discount_exhausted');

    $this->stripe->expireCheckoutSession($first->stripe_checkout_session_id);
    $this->payments->close($first, OrderStatus::Expired, 'checkout_expired', $this->stripe->retrieveCheckoutSession($first->stripe_checkout_session_id));

    expect($code->refresh()->redeemed_count)->toBe(0)
        ->and(reserve($b, [line(passType(), $b)], 'ONE')->discount_cents)->toBeGreaterThan(0)
        ->and($code->refresh()->redeemed_count)->toBe(1);
});

it('pagamento tardivo con codice intanto esaurito: il biglietto vale, il contatore non sfora e lo staff è avvisato', function () {
    Mail::fake();
    $code = DiscountCode::factory()->create(['code' => 'ONE', 'max_redemptions' => 1]);
    [$a, $b] = User::factory()->count(2)->create();

    $late = reserveAndCheckout($a, [line(passType(), $a)], 'ONE');
    $this->stripe->expireCheckoutSession($late->stripe_checkout_session_id);
    $this->payments->close($late, OrderStatus::Expired, 'checkout_expired', $this->stripe->retrieveCheckoutSession($late->stripe_checkout_session_id));
    reserve($b, [line(passType(), $b)], 'ONE');   // B prende l'ultimo utilizzo
    $this->stripe->sessions[$late->stripe_checkout_session_id]['status'] = 'open';

    $result = $this->payments->markPaid($late, $this->stripe->pay($late->stripe_checkout_session_id));

    expect($result)->toBe('paid_late')
        ->and($code->refresh()->redeemed_count)->toBeLessThanOrEqual(1);
    Mail::assertQueued(StaffAlert::class, fn ($m) => str_contains($m->title, 'discount'));
});

it('importo Stripe diverso dal totale: ordine pagato ma avviso allo staff', function () {
    Mail::fake();
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    $session = $this->stripe->pay($order->stripe_checkout_session_id);
    $session['amount_total'] = $order->total_cents - 100;

    expect($this->payments->markPaid($order, $session))->toBe('paid')
        ->and($order->refresh()->stripe_amount_total_cents)->toBe($order->total_cents - 100);
    Mail::assertQueued(StaffAlert::class, fn ($m) => str_contains($m->title, 'Importo'));
});

it('webhook di un ordine sconosciuto: evento failed, nessun ordine toccato', function () {
    $session = ['id' => 'cs_unknown', 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'complete',
        'payment_status' => 'paid', 'client_reference_id' => (string) Str::uuid(), 'metadata' => ['order_id' => (string) Str::uuid()]];

    Queue::fake([ProcessStripeEvent::class]);   // in produzione la coda è database: il job gira dopo il salvataggio
    $id = qaWebhook('checkout.session.completed', $session);
    expect(fn () => app()->call([new ProcessStripeEvent($id), 'handle']))->toThrow(Exception::class);

    $event = StripeEvent::sole();
    expect($event->status)->toBe(StripeEventStatus::Failed)
        ->and(Order::where('status', OrderStatus::Paid)->count())->toBe(0);
});

it('charge.refunded di un pagamento sconosciuto non crea rimborsi', function () {
    $id = qaWebhook('charge.refunded', ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => 'pi_nobody', 'amount_refunded' => 5000, 'refunded' => true]);

    expect(StripeEvent::find($id)->status)->toBe(StripeEventStatus::Processed)
        ->and(\App\Models\Refund::count())->toBe(0);
});

it('rimborso doppio: staff, webhook ripetuto due volte, nuovo clic → una sola chiamata e un solo rimborso', function () {
    $buyer = User::factory()->create();
    $order = qaPaid($buyer);
    $admin = User::where('email', '!=', $buyer->email)->whereRelation('roles', 'name', StaffRole::Admin->value)->first();

    RefundOrderFully::dispatch($order->id, 'other', $admin->id, 'test');
    qaWebhook('charge.refunded', qaCharge($order, $order->total_cents));
    qaWebhook('charge.refunded', qaCharge($order, $order->total_cents));   // evento diverso, stesso stato
    RefundOrderFully::dispatch($order->id, 'other', $admin->id, 'test');

    $order->refresh();
    expect($this->stripe->callsTo('createRefund'))->toHaveCount(1)
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and((int) $order->refunds()->sum('amount_cents'))->toBe($order->total_cents)
        ->and($order->tickets()->where('status', TicketStatus::Refunded)->count())->toBe(1);
});

it('rimborso parziale dalla Dashboard e poi completo dallo staff: si rimborsa solo il resto', function () {
    $buyer = User::factory()->create();
    $order = qaPaid($buyer);

    qaWebhook('charge.refunded', qaCharge($order, 1000));
    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->tickets()->where('status', TicketStatus::Valid)->count())->toBe(1);

    RefundOrderFully::dispatch($order->id, 'other', User::factory()->staff(StaffRole::Commerciale)->create()->id, 'resto');

    [$call] = $this->stripe->callsTo('createRefund');
    expect($call[1]['amount'])->toBe($order->total_cents - 1000);
});

it('l\'organizzatore non può rimborsare dal pannello', function () {
    Queue::fake([RefundOrderFully::class]);
    $order = qaPaid(User::factory()->create());
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    expect(auth()->user()->can('refund', $order))->toBeFalse();
    try {
        Livewire::test(ManageOrders::class)
            ->callAction(TestAction::make('refund')->table($order), data: ['cause' => 'other', 'reason' => 'x']);
    } catch (Throwable) {
        // azione negata: va bene in qualunque forma, purché il rimborso non parta
    }
    Queue::assertNotPushed(RefundOrderFully::class);
});

it('QR: niente per i biglietti di altri né per un biglietto rimborsato', function () {
    $owner = User::factory()->create();
    $order = qaPaid($owner);
    $ticket = $order->tickets()->sole();

    $this->actingAs(User::factory()->create())->get(route('account.tickets.qr', $ticket))->assertNotFound();
    $this->actingAs($owner)->get(route('account.tickets.qr', $ticket))->assertOk();

    $ticket->update(['status' => TicketStatus::Refunded, 'cancelled_at' => now()]);
    $this->actingAs($owner)->get(route('account.tickets.qr', $ticket))->assertNotFound();
});

it('export: nessun accesso al file di un altro staff, neanche con un percorso costruito', function () {
    Storage::fake('local');
    $owner = User::factory()->staff(StaffRole::Commerciale)->create();
    Storage::disk('local')->put("exports/{$owner->id}/orders-x.csv", 'secret');
    $other = User::factory()->staff(StaffRole::Commerciale)->create();

    $this->actingAs($other)->get('/admin/exports/orders-x.csv')->assertNotFound();
    $this->actingAs($other)->get('/admin/exports/..%2F'.$owner->id.'%2Forders-x.csv')->assertNotFound();
    $this->get('/admin/exports/orders-..%2F..%2F'.$owner->id.'%2Forders-x.csv')->assertNotFound();
});
