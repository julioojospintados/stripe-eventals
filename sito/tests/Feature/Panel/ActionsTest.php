<?php

// Azioni del pannello: omaggio, correzione del nominativo, rimborso, fasce sovrapposte (tests/TODO-passo-2.md).

use App\Enums\CompReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Filament\Resources\DiscountCodes\Pages\ManageDiscountCodes;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\TicketPrices\Pages\ManageTicketPrices;
use App\Filament\Resources\Tickets\Pages\ManageTickets;
use App\Jobs\RefundOrderFully;
use App\Mail\TicketsMail;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ShopException;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->event = eas8();
    $this->staffPass = $this->event->ticketTypes()->where('kind', TicketKind::StaffPass)->sole();
});

function paidOrder(): Order
{
    $buyer = User::factory()->create(['name' => 'Ana Silva']);
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    app(PaymentService::class)->markPaid($order, test()->stripe->pay($order->stripe_checkout_session_id));

    return $order->refresh();
}

it('emette un omaggio a 0 € che conta nella capienza, con email e audit', function () {
    Mail::fake();
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    Livewire::test(ManageOrders::class)
        ->callAction('issueComplimentary', data: [
            'ticket_type_id' => $this->staffPass->id, 'name' => 'Dr Speaker', 'email' => 'Speaker@Example.test',
            'reason' => CompReason::Speaker->value,
        ])
        ->assertHasNoActionErrors();

    $order = Order::where('origin', OrderOrigin::Complimentary)->sole();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total_cents)->toBe(0)
        ->and($order->tickets()->sole()->attendee_email)->toBe('speaker@example.test')
        ->and(CapacityPool::where('name', 'like', 'Day %')->pluck('confirmed')->all())->toBe([1, 1, 1])
        ->and(AuditLog::where('action', 'order.complimentary_issued')->count())->toBe(1);
    Mail::assertSent(TicketsMail::class);
});

it('rifiuta l\'omaggio a pool pieno e lo revoca liberando il posto', function () {
    $staff = User::factory()->staff(StaffRole::Organizzatore)->create();
    $this->actingAs($staff);
    $day1 = CapacityPool::where('name', 'like', 'Day 1%')->sole();
    $day1->update(['capacity' => 0]);
    $data = ['ticket_type_id' => $this->staffPass->id, 'name' => 'Staff One', 'email' => 'one@example.test', 'reason' => 'staff'];

    Livewire::test(ManageOrders::class)->callAction('issueComplimentary', data: $data)->assertNotified(__('shop.sold_out'));
    expect(Order::where('origin', OrderOrigin::Complimentary)->count())->toBe(0)
        ->and(CapacityPool::sum('confirmed'))->toBe(0);

    $day1->update(['capacity' => 6000]);
    Livewire::test(ManageOrders::class)->callAction('issueComplimentary', data: $data);
    $order = Order::where('origin', OrderOrigin::Complimentary)->sole();

    Livewire::test(ManageOrders::class)->callAction(TestAction::make('revoke')->table($order), data: ['note' => 'Cancelled talk']);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->tickets()->sole()->status)->toBe(TicketStatus::Cancelled)
        ->and(CapacityPool::sum('confirmed'))->toBe(0)
        ->and(AuditLog::where('action', 'order.complimentary_revoked')->count())->toBe(1);
});

it('corregge un refuso nel nome (solo admin), al massimo 3 volte, con audit e nuovo QR se cambia l\'email', function () {
    $ticket = paidOrder()->tickets()->sole();
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create());
    $correct = fn (array $data) => Livewire::test(ManageTickets::class)
        ->callAction(TestAction::make('correctAttendee')->table($ticket), data: $data + ['reason' => 'Typo']);

    $correct(['name' => 'Ana Sílva', 'email' => $ticket->attendee_email]);
    expect($ticket->refresh()->attendee_name)->toBe('Ana Sílva')->and($ticket->qr_version)->toBe(1);

    $this->travel(2)->seconds();
    $correct(['name' => 'Ana Sílva', 'email' => 'ana.silva@example.test']);
    expect($ticket->refresh()->qr_version)->toBe(2)->and($ticket->attendee_corrections_count)->toBe(2);

    $this->travel(2)->seconds();
    $correct(['name' => 'Ana Silva', 'email' => 'ana.silva@example.test']);
    expect($ticket->refresh()->attendee_corrections_count)->toBe(3)
        ->and(AuditLog::where('action', 'ticket.attendee_corrected')->count())->toBe(3);

    // Quarta: l'azione sparisce e il servizio rifiuta comunque.
    Livewire::test(ManageTickets::class)->assertActionHidden(TestAction::make('correctAttendee')->table($ticket));
    expect(fn () => app(TicketService::class)->correctAttendee($ticket, auth()->user(), 'X', 'x@example.test', 'r'))
        ->toThrow(ShopException::class, 'too_many_corrections');
});

it('rimborsa un ordine pagato dal pannello con il servizio del passo 2', function () {
    $order = paidOrder();
    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());

    Livewire::test(ManageOrders::class)
        ->callAction(TestAction::make('refund')->table($order), data: ['cause' => 'event_cancelled', 'reason' => 'Event cancelled'])
        ->assertHasNoActionErrors();

    [$call] = $this->stripe->callsTo('createRefund');
    $refund = $order->refunds()->sole();
    expect($call[2])->toBe("staff-refund-{$order->id}")
        ->and($call[1]['amount'])->toBe($order->total_cents)
        ->and($refund->source)->toBe('staff')
        ->and($refund->cause)->toBe('event_cancelled')
        ->and(AuditLog::where('action', 'order.refund_requested')->count())->toBe(1);

    // Un secondo clic non rimborsa due volte.
    RefundOrderFully::dispatch($order->id, 'event_cancelled', auth()->id());
    expect($this->stripe->callsTo('createRefund'))->toHaveCount(1);
});

it('mostra un errore leggibile per fasce attive sovrapposte', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());

    Livewire::test(ManagePriceTiers::class)
        ->callAction(CreateAction::class, data: [
            'event_id' => $this->event->id, 'code' => 'flash', 'name' => ['en' => 'Flash sale'],
            'starts_at' => '2027-01-10 00:00:00', 'ends_at' => '2027-01-20 00:00:00', 'active' => true, 'position' => 9,
        ])
        ->assertHasActionErrors(['ends_at']);

    // Spenta non si sovrappone a niente.
    Livewire::test(ManagePriceTiers::class)
        ->callAction(CreateAction::class, data: [
            'event_id' => $this->event->id, 'code' => 'flash', 'name' => ['en' => 'Flash sale'],
            'starts_at' => '2027-01-10 00:00:00', 'ends_at' => '2027-01-20 00:00:00', 'active' => false, 'position' => 9,
        ])
        ->assertHasNoActionErrors();
});

it('crea un codice sconto con l\'autore nel registro audit e un prezzo in euro salvato in centesimi', function () {
    $commerciale = User::factory()->staff(StaffRole::Commerciale)->create();
    $this->actingAs($commerciale);

    Livewire::test(ManageDiscountCodes::class)
        ->callAction(CreateAction::class, data: ['code' => 'welcome10', 'event_id' => $this->event->id, 'discount_type' => 'percent', 'percent_off' => 10, 'active' => true])
        ->assertHasNoActionErrors();

    $code = DiscountCode::sole();
    expect($code->code)->toBe('WELCOME10')->and($code->created_by)->toBe($commerciale->id)->and($code->eligible_roles)->toBeNull()
        ->and(AuditLog::where('action', 'discount_code.created')->where('actor_id', $commerciale->id)->count())->toBe(1);

    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
    $tier = $this->event->priceTiers()->where('code', 'regular')->sole();
    Livewire::test(ManageTicketPrices::class)
        ->callAction(CreateAction::class, data: ['ticket_type_id' => passType()->id, 'price_tier_id' => $tier->id, 'price_role' => 'public', 'amount_cents' => '650.50'])
        ->assertHasNoActionErrors()
        ->callAction(CreateAction::class, data: ['ticket_type_id' => passType()->id, 'price_tier_id' => $tier->id, 'price_role' => 'public', 'amount_cents' => '600'])
        ->assertHasActionErrors(['price_role' => 'unique']);

    expect(passType()->prices()->where('price_tier_id', $tier->id)->sole()->amount_cents)->toBe(65050);
});
