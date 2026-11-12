<?php

// QA indipendente: rischi del multi-evento non coperti da MultiEventTest (membership, codici, webhook, scadenze, export).

use App\Enums\OrderStatus;
use App\Enums\TicketKind;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\MemberPriceClaim;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CsvExports;
use App\Services\PaymentService;
use App\Services\ShopException;
use Database\Seeders\DemoEventSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->eas = eas8();
    $this->demo = (new DemoEventSeeder('demo-ny', 'America/New_York', 'usd', published: true))->run();
    $this->demoPass = $this->demo->ticketTypes()->where('kind', TicketKind::FullPass)->sole();
});

function qaOrder(Event $event, $type, ?User $user = null, ?string $code = null): Order
{
    $user ??= User::factory()->create();
    $checkout = app(CheckoutService::class);
    $order = $checkout->reserve($user, $event, [line($type, $user)], $code);
    $checkout->startCheckout($order);

    return $order->refresh();
}

function qaCsv(string $type, string $eventId): array
{
    $h = fopen('php://memory', 'w+');
    app(CsvExports::class)->write($type, $h, $eventId);
    rewind($h);

    return [stream_get_contents($h)];
}

it('la membership vale in entrambi gli eventi ma il limite del prezzo membro è per evento e per valuta', function () {
    $user = member();
    $easOrder = qaOrder($this->eas, passType(), $user);
    $demoOrder = qaOrder($this->demo, $this->demoPass, $user);

    expect($easOrder->total_cents)->toBeLessThan(69500)
        ->and($demoOrder->total_cents)->toBe(29900)
        ->and($demoOrder->currency)->toBe('usd')
        ->and(MemberPriceClaim::where('user_id', $user->id)->pluck('event_id')->sort()->values()->all())
        ->toBe(collect([$this->eas->id, $this->demo->id])->sort()->values()->all());

    // Secondo biglietto da membro nello stesso evento: paga il pubblico, non consuma altro.
    $second = app(CheckoutService::class)->reserve($user, $this->demo, [line($this->demoPass, ['name' => 'X', 'email' => 'x@example.com'])]);
    expect($second->total_cents)->toBe(39900);
});

it('un codice di un evento non si applica all\'altro, in nessuna direzione', function () {
    DiscountCode::factory()->create(['code' => 'EASONLY', 'event_id' => $this->eas->id]);
    DiscountCode::factory()->create(['code' => 'NYONLY', 'event_id' => $this->demo->id]);
    $checkout = app(CheckoutService::class);
    $u = User::factory()->create();

    expect(fn () => $checkout->reserve($u, $this->demo, [line($this->demoPass, $u)], 'EASONLY'))->toThrow(ShopException::class, 'discount_invalid')
        ->and(fn () => $checkout->reserve($u, $this->eas, [line(passType(), $u)], 'nyonly'))->toThrow(ShopException::class, 'discount_invalid')
        ->and(Order::count())->toBe(0)
        ->and(DiscountCode::where('code', 'EASONLY')->value('redeemed_count') ?? 0)->toBe(0);
});

it('il webhook di un ordine dell\'altro evento non tocca gli ordini del primo', function () {
    $demoOrder = qaOrder($this->demo, $this->demoPass);
    $easOrder = qaOrder($this->eas, passType());
    $session = $this->stripe->pay($demoOrder->stripe_checkout_session_id);

    postWebhook(stripeEvent('checkout.session.completed', $session))->assertOk();

    expect($demoOrder->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($easOrder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($easOrder->tickets()->count())->toBe(0)
        ->and((int) CapacityPool::where('event_id', $this->eas->id)->sum('confirmed'))->toBe(0)
        ->and((int) CapacityPool::where('event_id', $this->eas->id)->sum('held'))->toBeGreaterThan(0);
});

it('una Checkout Session in valuta diversa da quella dell\'evento non emette biglietti', function () {
    $order = qaOrder($this->demo, $this->demoPass);
    $session = $this->stripe->pay($order->stripe_checkout_session_id);
    $session['currency'] = 'eur';   // stesso numero, altra moneta

    expect(app(PaymentService::class)->markPaid($order, $session))->toBe('needs_review')
        ->and($order->refresh()->status)->toBe(OrderStatus::NeedsReview)
        ->and($order->tickets()->count())->toBe(0);

    $ok = qaOrder($this->demo, $this->demoPass);
    $paid = $this->stripe->pay($ok->stripe_checkout_session_id);
    $paid['currency'] = 'USD';   // Stripe scrive minuscolo, ma la maiuscola non deve far fallire
    expect(app(PaymentService::class)->markPaid($ok, $paid))->toBe('paid');
});

it('la scadenza degli ordini rilascia i posti solo dell\'evento dell\'ordine', function () {
    $demoOrder = qaOrder($this->demo, $this->demoPass);
    $easOrder = qaOrder($this->eas, passType());
    $demoOrder->update(['checkout_expires_at' => now()->subMinutes(10)]);
    $easHeld = (int) CapacityPool::where('event_id', $this->eas->id)->sum('held');

    $this->artisan('eventals:expire-orders')->assertSuccessful();

    expect($demoOrder->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($easOrder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and((int) CapacityPool::where('event_id', $this->demo->id)->sum('held'))->toBe(0)
        ->and((int) CapacityPool::where('event_id', $this->eas->id)->sum('held'))->toBe($easHeld);
});

it('gli export di un evento non contengono ordini né biglietti dell\'altro', function () {
    $orders = [];
    foreach ([[$this->demo, $this->demoPass], [$this->eas, passType()]] as [$event, $type]) {
        $o = qaOrder($event, $type);
        app(PaymentService::class)->markPaid($o, $this->stripe->pay($o->stripe_checkout_session_id));
        $orders[$event->id] = $o->refresh();
    }
    $flat = fn (array $rows) => json_encode($rows);

    foreach ([$this->demo, $this->eas] as $event) {
        $other = $orders[($event->is($this->demo) ? $this->eas : $this->demo)->id];
        $mine = $orders[$event->id];
        foreach (['orders', 'tickets'] as $type) {
            $csv = $flat(qaCsv($type, $event->id));
            expect($csv)->not->toContain($other->tickets()->first()->short_code)->and($csv)->not->toContain($other->buyer_email)
                ->and($csv)->toContain($type === 'tickets' ? $mine->tickets()->first()->short_code : $mine->buyer_email);
        }
    }
});

it('Event::current() senza eventi pubblicati dà null e le pagine pubbliche rispondono 404', function () {
    Event::query()->update(['status' => 'draft']);

    expect(Event::current())->toBeNull();
    $this->get('/')->assertNotFound();   // nessun evento: 404 pulito, non 500
    $this->get('/tickets')->assertNotFound();
});

it('Event::current() ignora le bozze, anche se EVENTALS_EVENT le nomina', function () {
    $this->demo->update(['status' => 'draft']);
    config(['eventals.event' => 'demo-ny']);

    expect(Event::current())->toBeNull();
    config(['eventals.event' => null]);
    expect(Event::current()->slug)->toBe($this->eas->slug);
});

it('con due eventi pubblicati vince il più vicino non finito, non il più recente', function () {
    $this->demo->update(['starts_on' => today()->addDays(3), 'ends_on' => today()->addDays(5)]);
    expect(Event::current()->slug)->toBe('demo-ny');

    $this->demo->update(['starts_on' => today()->subDays(5), 'ends_on' => today()->subDay()]);   // finito ieri
    expect(Event::current()->slug)->toBe($this->eas->slug);

    $this->demo->update(['starts_on' => today()->subDay(), 'ends_on' => today()]);   // finisce oggi: conta ancora
    expect(Event::current()->slug)->toBe('demo-ny');
});
