<?php

// Regole del database su tipi di biglietto, fasce, prezzi, capienza ed eventi Stripe.
// Portate da db/prove/prove_mysql.php (sezioni 1, 3, 4); ogni prova controlla il nome del vincolo o del trigger.

use App\Enums\PriceRole;
use App\Enums\TicketChannel;
use App\Models\CapacityPool;
use App\Models\Event;
use App\Models\Order;
use App\Models\PriceTier;
use App\Models\TicketPrice;
use App\Models\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function tierFor(Event $event, string $code, string $from, string $to, bool $active = true): PriceTier
{
    return PriceTier::factory()->create([
        'event_id' => $event->id, 'code' => $code, 'starts_at' => $from, 'ends_at' => $to, 'active' => $active,
    ]);
}

describe('fasce di prezzo', function () {
    beforeEach(function () {
        $this->event = Event::factory()->create();
        $this->early = tierFor($this->event, 'early_bird', '2026-11-10 00:00:00', '2027-02-01 00:00:00');
        tierFor($this->event, 'regular', '2027-02-01 00:00:00', '2027-04-01 00:00:00');
    });

    it('rifiuta una fascia sovrapposta a un\'altra dello stesso evento', function () {
        requireTrigger('price_tiers_no_overlap_insert');

        expect(fn () => tierFor($this->event, 'promo', '2027-01-15 00:00:00', '2027-02-15 00:00:00'))
            ->toThrow(QueryException::class, 'price_tier_overlap');
    });

    it('rifiuta una fascia spostata sopra un\'altra', function () {
        requireTrigger('price_tiers_no_overlap_update');

        expect(fn () => $this->early->update(['ends_at' => '2027-02-10 00:00:00']))
            ->toThrow(QueryException::class, 'price_tier_overlap');
    });

    it('ammette una fascia adiacente (fine = inizio della successiva)', function () {
        $last = tierFor($this->event, 'last_minute', '2027-04-01 00:00:00', '2027-04-10 18:00:00');

        expect($last->exists)->toBeTrue();
    });

    it('ammette sovrapposta una fascia spenta, ma non la riaccende', function () {
        requireTrigger('price_tiers_no_overlap_update');
        $off = tierFor($this->event, 'promo', '2027-01-15 00:00:00', '2027-02-15 00:00:00', active: false);

        expect(fn () => $off->update(['active' => true]))->toThrow(QueryException::class, 'price_tier_overlap');
    });

    it('rifiuta una fascia che finisce prima di cominciare', function () {
        expect(fn () => tierFor($this->event, 'promo', '2028-02-01 00:00:00', '2028-01-01 00:00:00'))
            ->toThrow(QueryException::class, 'price_tiers_window');
    });
});

describe('prezzi', function () {
    beforeEach(function () {
        $this->pass = TicketType::factory()->create();
        $this->early = tierFor($this->pass->event, 'early_bird', '2026-11-10 00:00:00', '2027-02-01 00:00:00');
        TicketPrice::factory()->for($this->pass)->create(['price_role' => PriceRole::Public, 'amount_cents' => 69500]);
        TicketPrice::factory()->for($this->pass)->create([
            'price_role' => PriceRole::Member, 'price_tier_id' => $this->early->id, 'amount_cents' => 27650,
        ]);
    });

    it('rifiuta un secondo prezzo base per lo stesso tipo e ruolo', function () {
        expect(fn () => TicketPrice::factory()->for($this->pass)->create(['price_role' => PriceRole::Public]))
            ->toThrow(QueryException::class, 'ticket_prices_one_per_tier_role');
    });

    it('rifiuta un secondo prezzo early bird per lo stesso tipo e ruolo', function () {
        expect(fn () => TicketPrice::factory()->for($this->pass)->create([
            'price_role' => PriceRole::Member, 'price_tier_id' => $this->early->id,
        ]))->toThrow(QueryException::class, 'ticket_prices_one_per_tier_role');
    });

    it('ammette prezzo base ed early bird per lo stesso tipo e ruolo', function () {
        $base = TicketPrice::factory()->for($this->pass)->create(['price_role' => PriceRole::Member, 'amount_cents' => 39500]);

        expect($base->exists)->toBeTrue();
    });
});

describe('tipi di biglietto', function () {
    it('rifiuta un omaggio vendibile sul sito', function () {
        expect(fn () => TicketType::factory()->staffPass()->create(['channel' => TicketChannel::Public]))
            ->toThrow(QueryException::class, 'ticket_types_channel_shape');
    });

    it('rifiuta un accompagnatore senza limite per pass', function () {
        expect(fn () => TicketType::factory()->companion()->create(['max_per_host' => null]))
            ->toThrow(QueryException::class, 'ticket_types_channel_shape');
    });

    it('rifiuta un workshop senza sessione', function () {
        expect(fn () => TicketType::factory()->workshop()->create(['program_session_id' => null]))
            ->toThrow(QueryException::class, 'ticket_types_kind_shape');
    });
});

describe('capienza', function () {
    beforeEach(function () {
        $this->pool = CapacityPool::factory()->create(['capacity' => 1]);
        $this->order = Order::factory()->create(['event_id' => $this->pool->event_id]);
    });

    it('rifiuta un aumento cieco dei venduti oltre la capienza', function () {
        expect(fn () => DB::table('capacity_pools')->where('id', $this->pool->id)->increment('confirmed', 2))
            ->toThrow(QueryException::class, 'capacity_pools_no_oversell');
    });

    it('rifiuta la capienza ridotta sotto i posti presi', function () {
        $pool = CapacityPool::factory()->create(['capacity' => 2]);
        DB::table('capacity_pools')->where('id', $pool->id)->update(['confirmed' => 2]);

        expect(fn () => DB::table('capacity_pools')->where('id', $pool->id)->update(['capacity' => 1]))
            ->toThrow(QueryException::class, 'capacity_pools_no_oversell');
    });

    it('rifiuta una riserva attiva senza scadenza', function () {
        expect(fn () => $this->order->holds()->create(['pool_id' => $this->pool->id, 'quantity' => 1, 'status' => 'active']))
            ->toThrow(QueryException::class, 'capacity_holds_active_expires');
    });

    it('rifiuta una riserva rilasciata senza data', function () {
        expect(fn () => $this->order->holds()->create(['pool_id' => $this->pool->id, 'quantity' => 1, 'status' => 'released']))
            ->toThrow(QueryException::class, 'capacity_holds_released_dated');
    });

    it('rifiuta una doppia riserva per lo stesso ordine e pool', function () {
        $hold = ['pool_id' => $this->pool->id, 'quantity' => 1, 'status' => 'active', 'expires_at' => now()->addMinutes(36)];
        $this->order->holds()->create($hold);

        expect(fn () => $this->order->holds()->create($hold))
            ->toThrow(QueryException::class, 'capacity_holds_order_id_pool_id_unique');
    });
});

describe('eventi Stripe', function () {
    $event = fn (string $id, array $extra = []) => array_merge([
        'id' => $id, 'type' => 'checkout.session.completed', 'livemode' => false, 'stripe_created_at' => now(),
    ], $extra);

    it('salva una volta sola un evento ripetuto', function () use ($event) {
        DB::table('stripe_events')->insertOrIgnore($event('evt_1abc'));
        DB::table('stripe_events')->insertOrIgnore($event('evt_1abc'));

        expect(DB::table('stripe_events')->where('id', 'evt_1abc')->count())->toBe(1);
    });

    it('rifiuta un id senza evt_ (e il _ non fa da jolly)', function (string $id) use ($event) {
        expect(fn () => DB::table('stripe_events')->insert($event($id)))
            ->toThrow(QueryException::class, 'stripe_events_id_prefix');
    })->with(['xyz_1', 'evtX1']);

    it('rifiuta un evento processed senza data', function () use ($event) {
        expect(fn () => DB::table('stripe_events')->insert($event('evt_2', ['status' => 'processed'])))
            ->toThrow(QueryException::class, 'stripe_events_processed_dated');
    });
});
