<?php

// Due eventi nella stessa installazione con fuso e valuta diversi (ADR 0011): prezzi, fasce, capienza, codici sconto,
// pagine, export e riconciliazione restano separati. L'EAS8 (Lisbona, eur) e un demo pubblicato (New York, usd).

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Enums\TicketKind;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\SessionSpeaker;
use App\Models\Speaker;
use App\Models\TicketPrice;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CsvExports;
use App\Services\PaymentService;
use App\Services\PriceResolver;
use App\Services\ProgramService;
use App\Services\ShopException;
use Database\Seeders\DemoEventSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->eas = eas8();
    $this->demo = (new DemoEventSeeder('demo-ny', 'America/New_York', 'usd', published: true))->run();
    $this->demoPass = $this->demo->ticketTypes()->where('kind', TicketKind::FullPass)->sole();
});

function buyFor(Event $event, TicketType $type, ?User $buyer = null): Order
{
    $buyer ??= User::factory()->create();
    $checkout = app(CheckoutService::class);
    $order = $checkout->reserve($buyer, $event, [line($type, $buyer)]);
    $checkout->startCheckout($order);

    return $order->refresh();
}

function csvOf(string $type, ?string $eventId): array
{
    $handle = fopen('php://memory', 'w+');
    app(CsvExports::class)->write($type, $handle, $eventId);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    return array_slice($rows, 1);
}

it('sceglie l\'evento pubblico: EVENTALS_EVENT, poi il prossimo non finito, poi l\'ultimo', function () {
    expect(Event::current()->slug)->toBe('eas8-lisbon-2027');   // aprile 2027 viene prima del demo (tra 6 mesi)

    config(['eventals.event' => 'demo-ny']);
    expect(Event::current()->slug)->toBe('demo-ny');

    config(['eventals.event' => null]);
    Event::query()->update(['starts_on' => '2020-01-01', 'ends_on' => '2020-01-02']);
    $this->demo->update(['starts_on' => '2021-01-01', 'ends_on' => '2021-01-02']);
    expect(Event::current()->slug)->toBe('demo-ny');
});

it('fasce e prezzi per evento, ordine e Stripe nella valuta dell\'evento', function () {
    $prices = app(PriceResolver::class);
    $easTier = $prices->activeTier($this->eas, now());
    $demoTier = $prices->activeTier($this->demo, now());

    expect($easTier->event_id)->toBe($this->eas->id)
        ->and($demoTier->event_id)->toBe($this->demo->id)
        ->and($prices->resolve($this->demoPass, PriceRole::Public, $demoTier)->amount_cents)->toBe(39900)
        ->and($prices->resolve(passType(), PriceRole::Public, $easTier)->amount_cents)->toBe(69500)
        ->and(TicketPrice::whereBelongsTo($this->demoPass)->pluck('currency')->unique()->all())->toBe(['usd']);

    $demoOrder = buyFor($this->demo, $this->demoPass);
    $easOrder = buyFor($this->eas, passType());
    [[, $demoParams], [, $easParams]] = $this->stripe->callsTo('createCheckoutSession');

    expect($demoOrder->only('currency', 'total_cents'))->toBe(['currency' => 'usd', 'total_cents' => 39900])
        ->and($demoParams['line_items'][0]['price_data'])->toMatchArray(['currency' => 'usd', 'unit_amount' => 39900])
        ->and($demoParams['metadata']['price_tier'])->toBe('early_bird')
        ->and($easOrder->currency)->toBe('eur')
        ->and($easParams['line_items'][0]['price_data']['currency'])->toBe('eur');
});

it('la capienza di un evento non tocca i pool dell\'altro', function () {
    buyFor($this->demo, $this->demoPass);

    expect(CapacityPool::where('event_id', $this->demo->id)->where('name', 'like', 'Day%')->pluck('held')->all())->toBe([1, 1])
        ->and((int) CapacityPool::where('event_id', $this->eas->id)->sum('held'))->toBe(0);
});

it('i trigger rifiutano prezzi, pool, codici e ordini mescolati tra eventi', function () {
    requireTrigger('ticket_prices_same_event_insert');
    $easTier = $this->eas->priceTiers()->first();

    expect(fn () => $this->demoPass->prices()->create(['price_role' => PriceRole::Member, 'price_tier_id' => $easTier->id, 'amount_cents' => 1]))
        ->toThrow(QueryException::class, 'ticket_price_tier_other_event')
        ->and(fn () => $this->demoPass->prices()->create(['price_role' => PriceRole::Member, 'price_tier_id' => null, 'amount_cents' => 1, 'currency' => 'eur']))
        ->toThrow(QueryException::class, 'ticket_price_currency_mismatch')
        ->and(fn () => $this->demoPass->pools()->attach($this->eas->capacityPools()->first()))
        ->toThrow(QueryException::class, 'pool_other_event')
        ->and(fn () => DiscountCode::factory()->create(['event_id' => $this->eas->id])->ticketTypes()->attach($this->demoPass))
        ->toThrow(QueryException::class, 'discount_code_type_other_event')
        ->and(fn () => Order::factory()->create(['event_id' => $this->demo->id, 'currency' => 'eur']))
        ->toThrow(QueryException::class, 'order_currency_mismatch')
        ->and(fn () => $this->demo->update(['currency' => 'gbp']))
        ->toThrow(QueryException::class, 'event_currency_locked');

    $empty = Event::factory()->create(['currency' => 'eur']);
    $empty->update(['currency' => 'gbp']);
    expect($empty->refresh()->currency)->toBe('gbp');
});

it('un codice a importo fisso vale solo nella sua valuta, quello in percentuale ovunque', function () {
    $amount = DiscountCode::factory()->create(['code' => 'FIXED50', 'event_id' => null, 'discount_type' => DiscountType::Amount,
        'percent_off' => null, 'amount_off_cents' => 5000]);
    $percent = DiscountCode::factory()->create(['code' => 'TEN', 'event_id' => null]);
    $scoped = DiscountCode::factory()->create(['code' => 'NYONLY', 'event_id' => $this->demo->id]);
    $buyer = User::factory()->create();
    $checkout = app(CheckoutService::class);

    expect($amount->currency)->toBe('eur')->and($scoped->currency)->toBe('usd')
        ->and(fn () => $checkout->reserve($buyer, $this->demo, [line($this->demoPass, $buyer)], 'FIXED50'))
        ->toThrow(ShopException::class, 'discount_invalid');

    expect($checkout->reserve($buyer, $this->demo, [line($this->demoPass, $buyer)], $percent->code)->discount_cents)->toBeGreaterThan(0)
        ->and($checkout->reserve($buyer, $this->eas, [line(passType(), $buyer)], 'FIXED50')->discount_cents)->toBe(5000);
});

it('le pagine pubbliche mostrano solo l\'evento corrente, con la sua valuta e il suo fuso', function () {
    $room = Room::factory()->create(['event_id' => $this->demo->id]);
    $day = $this->demo->starts_on->toDateString();
    $session = app(ProgramService::class)->save(new ProgramSession([
        'event_id' => $this->demo->id, 'code' => 'ny-1', 'title' => ['en' => 'Morning in New York'], 'room_id' => $room->id,
        'published' => true,
        'starts_at' => Carbon::parse("$day 09:00", 'America/New_York')->utc(), 'ends_at' => Carbon::parse("$day 10:00", 'America/New_York')->utc(),
    ]));
    $easSpeaker = Speaker::factory()->create(['last_name' => 'Lisboeta']);
    $easSession = scheduledSession(room(), '09:00', '10:00', ['title' => ['en' => 'Lisbon talk']]);
    SessionSpeaker::create(['program_session_id' => $easSession->id, 'speaker_id' => $easSpeaker->id, 'role' => 'speaker', 'position' => 1]);

    $this->get('/')->assertOk()->assertSee('EAS8 Lisbon')->assertSee('€695')->assertDontSee('Demo Conference');

    config(['eventals.event' => 'demo-ny']);
    $this->get('/')->assertOk()->assertSee('Demo Conference')->assertSee('US$399')->assertDontSee('€695');
    $this->get('/tickets')->assertOk()->assertSee('US$399')->assertDontSee('Workshop 10');
    $this->get('/programme?day='.$day)->assertOk()->assertSee('Morning in New York')->assertSee('09:00')->assertDontSee('Lisbon talk');
    $this->get('/programme/'.$session->slug)->assertOk();
    $this->get('/programme/'.$easSession->slug)->assertNotFound();
    $this->get('/speakers')->assertOk()->assertDontSee('Lisboeta');
    $this->get('/speakers/'.$easSpeaker->slug)->assertNotFound();
});

it('gli export di biglietti e ordini sono per evento', function () {
    foreach ([[$this->demo, $this->demoPass], [$this->eas, passType()]] as [$event, $type]) {
        $order = buyFor($event, $type);
        app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));
    }

    $demoOrders = csvOf('orders', $this->demo->id);
    expect(csvOf('tickets', $this->demo->id))->toHaveCount(1)
        ->and(csvOf('tickets', null))->toHaveCount(2)
        ->and($demoOrders)->toHaveCount(1)
        ->and($demoOrders[0][8])->toBe('usd')
        ->and(app(CsvExports::class)->count('orders', $this->eas->id))->toBe(1);
});

it('la riconciliazione rielabora gli ordini di entrambi gli eventi, ognuno sul suo', function () {
    $demoOrder = buyFor($this->demo, $this->demoPass);
    $easOrder = buyFor($this->eas, passType());
    $this->stripe->pay($demoOrder->stripe_checkout_session_id);   // webhook persi
    $this->stripe->pay($easOrder->stripe_checkout_session_id);

    $this->artisan('eventals:reconcile')->expectsOutput('2 differenze.')->assertSuccessful();

    foreach ([[$demoOrder, $this->demo], [$easOrder, $this->eas]] as [$order, $event]) {
        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->tickets()->sole()->event_id)->toBe($event->id)
            ->and($order->currency)->toBe($event->currency);
    }
});

it('i comandi hanno il prefisso eventals: e tengono gli alias eas: deprecati', function () {
    $this->artisan('eventals:expire-orders')->assertSuccessful();
    $this->artisan('eas:expire-orders')->assertSuccessful();
});
