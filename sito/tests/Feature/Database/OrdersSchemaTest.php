<?php

// Regole del database su ordini, omaggi, biglietti, accompagnatori e prezzo membro.
// Portate da db/prove/prove_mysql.php (sezioni 2, 5, 6); ogni prova controlla il nome del vincolo o del trigger.

use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Models\CompanionClaim;
use App\Models\MemberPriceClaim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('ordini', function () {
    beforeEach(function () {
        $this->order = Order::factory()->create();
    });

    it('rifiuta un secondo ordine pending della stessa persona', function () {
        expect(fn () => Order::factory()->create(['user_id' => $this->order->user_id, 'event_id' => $this->order->event_id]))
            ->toThrow(QueryException::class, 'orders_one_pending_per_user');
    });

    it('rifiuta un totale diverso da subtotale meno sconto', function () {
        expect(fn () => Order::factory()->create(['total_cents' => 1]))
            ->toThrow(QueryException::class, 'orders_amounts_valid');
    });

    it('rifiuta un ordine paid senza data', function () {
        expect(fn () => Order::factory()->create(['status' => OrderStatus::Paid]))
            ->toThrow(QueryException::class, 'orders_paid_has_date');
    });

    it('rifiuta la transizione pending → refunded', function () {
        requireTrigger('orders_guard');

        expect(fn () => $this->order->update(['status' => OrderStatus::Refunded]))
            ->toThrow(QueryException::class, 'order_invalid_transition');
        expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Refunded))->toBeFalse();
    });

    it('non cambia fascia né importi dopo la riserva', function (array $change) {
        requireTrigger('orders_guard');

        expect(fn () => $this->order->update($change))->toThrow(QueryException::class, 'order_snapshot_immutable');
    })->with([
        'fascia' => [fn () => ['price_tier_id' => PriceTier::factory()->create()->id]],
        'totale' => [['subtotal_cents' => 1, 'total_cents' => 1]],
    ]);

    it('non annulla senza rimborso un ordine pagato con Stripe', function () {
        requireTrigger('orders_guard');
        $paid = Order::factory()->paid()->create();

        expect(fn () => $paid->update(['status' => OrderStatus::Cancelled]))
            ->toThrow(QueryException::class, 'order_invalid_transition');
    });

    it('rifiuta una riga a prezzo membro di quantità 2', function () {
        expect(fn () => OrderItem::factory()->for($this->order)->create(['price_role' => PriceRole::Member, 'quantity' => 2]))
            ->toThrow(QueryException::class, 'order_items_member_qty_one');
    });

    it('rifiuta nominativi in numero diverso dalla quantità', function () {
        expect(fn () => OrderItem::factory()->for($this->order)->create(['quantity' => 2, 'attendees' => [['name' => 'A']]]))
            ->toThrow(QueryException::class, 'order_items_attendees_match');
    });
});

describe('omaggi', function () {
    it('rifiuta un omaggio malformato', function (array $change) {
        expect(fn () => Order::factory()->complimentary()->create($change))
            ->toThrow(QueryException::class, 'orders_comp_shape');
    })->with([
        'con importo' => [['subtotal_cents' => 100, 'total_cents' => 100]],
        'senza chi lo emette' => [['issued_by' => null]],
        'senza motivo' => [['comp_reason' => null]],
        'pending' => [['status' => OrderStatus::Pending, 'paid_at' => null]],
    ]);

    it('emette un omaggio a chi ha un checkout aperto e lo revoca', function () {
        $pending = Order::factory()->create();
        $comp = Order::factory()->complimentary()->create(['user_id' => $pending->user_id, 'event_id' => $pending->event_id]);

        $comp->update(['status' => OrderStatus::Cancelled]);

        expect($comp->fresh()->status)->toBe(OrderStatus::Cancelled);
    });
});

describe('biglietti', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create();
        $this->ticket = Ticket::factory()->create(['attendee_name' => 'Mario Rosi']);
        $this->tickets = DB::table('tickets')->where('id', $this->ticket->id);
    });

    it('rifiuta un secondo biglietto per la stessa riga e numero', function () {
        expect(fn () => Ticket::factory()->create(['order_item_id' => $this->ticket->order_item_id, 'seq' => 1]))
            ->toThrow(QueryException::class, 'tickets_one_per_item_seq');
    });

    it('non cambia il nominativo', function () {
        requireTrigger('tickets_guard');

        expect(fn () => $this->tickets->update(['attendee_name' => 'Luigi Verdi', 'attendee_email' => 'luigi@example.test']))
            ->toThrow(QueryException::class, 'ticket_attendee_immutable');
    });

    it('rifiuta una correzione senza la firma di chi corregge', function () {
        requireTrigger('tickets_guard');

        expect(fn () => $this->tickets->update(['attendee_name' => 'Mario Rossi', 'attendee_corrections_count' => 1]))
            ->toThrow(QueryException::class, 'ticket_attendee_immutable');
    });

    it('ammette 3 correzioni firmate di un refuso e rifiuta la quarta', function () {
        for ($k = 1; $k <= 3; $k++) {
            $this->tickets->update([
                'attendee_name' => "Mario Rossi $k",
                'attendee_corrections_count' => $k,
                'attendee_corrected_by' => $this->admin->id,
                'attendee_corrected_at' => now()->addSeconds($k),
            ]);
        }

        expect($this->ticket->fresh()->attendee_name)->toBe('Mario Rossi 3')
            ->and(fn () => $this->tickets->update([
                'attendee_name' => 'Mario Rossi 4', 'attendee_corrections_count' => 4,
                'attendee_corrected_by' => $this->admin->id, 'attendee_corrected_at' => now()->addSeconds(9),
            ]))->toThrow(QueryException::class, 'tickets_numbers_valid');
    });

    it('non cambia il tipo del biglietto', function () {
        requireTrigger('tickets_guard');
        $other = TicketType::factory()->create(['event_id' => $this->ticket->event_id]);

        expect(fn () => $this->tickets->update(['ticket_type_id' => $other->id]))
            ->toThrow(QueryException::class, 'ticket_immutable');
    });

    it('rifiuta un codice breve con caratteri ambigui', function () {
        expect(fn () => Ticket::factory()->create(['short_code' => 'ABCDEF01']))
            ->toThrow(QueryException::class, 'tickets_short_code_format');
    });

    it('non cancella un biglietto', function () {
        requireTrigger('tickets_no_delete');

        expect(fn () => $this->tickets->delete())->toThrow(QueryException::class, 'ticket_delete_forbidden');
    });

    it('non rende di nuovo valido un biglietto rimborsato', function () {
        requireTrigger('tickets_guard');
        $this->tickets->update(['status' => 'refunded', 'cancelled_at' => now()]);

        expect(fn () => $this->tickets->update(['status' => 'valid']))
            ->toThrow(QueryException::class, 'ticket_invalid_transition');
    });
});

describe('accompagnatori', function () {
    it('ammette un solo accompagnatore attivo per posto del pass', function () {
        $host = Ticket::factory()->create();
        $companion = TicketType::factory()->companion()->create(['event_id' => $host->event_id]);
        $claim = function () use ($host, $companion) {
            $order = Order::factory()->create(['event_id' => $host->event_id, 'status' => OrderStatus::Processing]);
            $item = OrderItem::factory()->for($order)->create(['ticket_type_id' => $companion->id]);

            return CompanionClaim::create([
                'host_ticket_id' => $host->id, 'slot' => 1, 'order_id' => $order->id, 'order_item_id' => $item->id,
            ]);
        };
        $first = $claim();

        expect($claim)->toThrow(QueryException::class, 'companion_claims_one_per_slot');

        $first->update(['released_at' => now(), 'release_reason' => 'order_expired']);
        expect($claim()->exists)->toBeTrue();
    });
});

describe('prezzo membro', function () {
    beforeEach(function () {
        $this->member = User::factory()->create();
        $this->pass = TicketType::factory()->create();
        $this->claim = function (string $kind = 'full_pass', array $extra = [], ?TicketType $type = null) {
            $order = Order::factory()->create([
                'user_id' => $this->member->id, 'event_id' => $this->pass->event_id,
                'status' => OrderStatus::Processing, 'price_role' => PriceRole::Member,
            ]);
            $item = OrderItem::factory()->for($order)->create([
                'ticket_type_id' => ($type ?? $this->pass)->id, 'price_role' => PriceRole::Member,
            ]);

            return MemberPriceClaim::create(array_merge([
                'user_id' => $this->member->id, 'event_id' => $this->pass->event_id, 'kind' => $kind,
                'order_id' => $order->id, 'order_item_id' => $item->id,
            ], $extra));
        };
    });

    it('ammette un solo pass a prezzo membro per evento, anche su due ordini', function () {
        $first = ($this->claim)();

        expect(fn () => ($this->claim)())->toThrow(QueryException::class, 'member_price_claims_one_active');

        $first->update(['released_at' => now(), 'release_reason' => 'refunded']);
        expect(($this->claim)()->exists)->toBeTrue();
    });

    it('ammette il workshop a prezzo membro accanto al pass', function () {
        ($this->claim)();
        $workshop = TicketType::factory()->workshop()->create(['event_id' => $this->pass->event_id]);

        $claim = ($this->claim)('paid_session', ['program_session_id' => $workshop->program_session_id], $workshop);

        expect($claim->exists)->toBeTrue();
    });

    it('rifiuta una chiave di forma sbagliata (pass con sessione)', function () {
        $workshop = TicketType::factory()->workshop()->create(['event_id' => $this->pass->event_id]);

        expect(fn () => ($this->claim)('full_pass', ['program_session_id' => $workshop->program_session_id]))
            ->toThrow(QueryException::class, 'member_price_claims_kind_shape');
    });
});
