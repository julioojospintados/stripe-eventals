<?php

// Pannello: ogni ruolo vede e fa solo il suo (docs/architettura.md §5.3), 403 altrimenti.

use App\Enums\StaffRole;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Resources\CapacityPools\Pages\ManageCapacityPools;
use App\Filament\Resources\DiscountCodes\Pages\ManageDiscountCodes;
use App\Filament\Resources\Events\Pages\ManageEvents;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\TicketPrices\Pages\ManageTicketPrices;
use App\Filament\Resources\Tickets\Pages\ManageTickets;
use App\Filament\Resources\TicketTypes\Pages\ManageTicketTypes;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PriceTier;
use App\Models\Ticket;
use App\Models\TicketPrice;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    eas8();
});

function staffUser(StaffRole $role): User
{
    return User::factory()->staff($role)->create();
}

$pages = ['', 'events', 'ticket-types', 'price-tiers', 'ticket-prices', 'capacity-pools', 'discount-codes', 'orders',
    'tickets', 'memberships', 'disputes', 'membership-plans', 'staff-users', 'roles', 'audit-logs', 'exports'];

$allowed = [
    'admin' => $pages,
    'commerciale' => ['', 'events', 'ticket-types', 'price-tiers', 'ticket-prices', 'capacity-pools', 'discount-codes',
        'orders', 'tickets', 'memberships', 'disputes', 'membership-plans', 'exports'],
    'organizzatore' => ['', 'events', 'ticket-types', 'price-tiers', 'ticket-prices', 'capacity-pools', 'discount-codes',
        'orders', 'tickets', 'exports'],
];

it('apre a ogni ruolo solo le sue pagine', function (StaffRole $role) use ($pages, $allowed) {
    $this->actingAs(staffUser($role));

    foreach ($pages as $page) {
        $expected = in_array($page, $allowed[$role->value], true) ? 200 : 403;
        expect($this->get('/admin/'.$page)->status())->toBe($expected, "/admin/$page per {$role->value}");
    }
})->with(StaffRole::cases());

it('chiude il pannello a chi compra', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/orders')->assertForbidden();
    $this->get('/admin/exports')->assertForbidden();
});

it('fa modificare evento e tipi solo a organizzatore e admin', function () {
    $event = Event::first();

    $this->actingAs(staffUser(StaffRole::Commerciale));
    Livewire::test(ManageEvents::class)->assertActionHidden(CreateAction::class)
        ->assertActionHidden(TestAction::make(EditAction::class)->table($event));
    Livewire::test(ManageTicketTypes::class)->assertActionHidden(CreateAction::class);

    $this->actingAs(staffUser(StaffRole::Organizzatore));
    Livewire::test(ManageEvents::class)->assertActionVisible(TestAction::make(EditAction::class)->table($event));
    Livewire::test(ManageTicketTypes::class)->assertActionVisible(CreateAction::class);
});

it('dà rimborso, omaggi e correzione del nome solo ai ruoli ammessi', function () {
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    app(PaymentService::class)->markPaid($order, $this->stripe->pay($order->stripe_checkout_session_id));
    $ticket = $order->tickets()->sole();

    $this->actingAs(staffUser(StaffRole::Organizzatore));
    Livewire::test(ManageOrders::class)
        ->assertActionHidden(TestAction::make('refund')->table($order))
        ->assertActionVisible('issueComplimentary')
        ->assertDontSee('€695');   // l'organizzatore non vede gli importi
    Livewire::test(ManageTickets::class)->assertActionHidden(TestAction::make('correctAttendee')->table($ticket));

    $this->actingAs(staffUser(StaffRole::Commerciale));
    Livewire::test(ManageOrders::class)
        ->assertActionVisible(TestAction::make('refund')->table($order))
        ->assertActionHidden('issueComplimentary')
        ->assertSee('€695');
    Livewire::test(ManageTickets::class)->assertActionHidden(TestAction::make('correctAttendee')->table($ticket));

    $this->actingAs(staffUser(StaffRole::Admin));
    Livewire::test(ManageTickets::class)->assertActionVisible(TestAction::make('correctAttendee')->table($ticket));
});

it('le policy rifiutano le azioni fuori dal ruolo', function () {
    $commerciale = staffUser(StaffRole::Commerciale);
    $organizzatore = staffUser(StaffRole::Organizzatore);

    expect($commerciale->can('update', Event::first()))->toBeFalse()
        ->and($commerciale->can('issueComplimentary', Order::class))->toBeFalse()
        ->and($commerciale->can('correctAttendee', Ticket::class))->toBeFalse()
        ->and($organizzatore->can('refund', Order::class))->toBeFalse()
        ->and($organizzatore->can('viewAny', Membership::class))->toBeFalse()
        ->and($organizzatore->can('viewAny', User::class))->toBeFalse()
        ->and($organizzatore->can('update', Event::first()))->toBeTrue()
        ->and($commerciale->can('create', DiscountCode::class))->toBeTrue()
        ->and($organizzatore->can('create', DiscountCode::class))->toBeTrue();
});

it('apre i form e le schede di ogni risorsa', function () {
    $this->actingAs(staffUser(StaffRole::Admin));
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    AuditLog::record('test.smoke');

    foreach ([
        ManageEvents::class => Event::first(),
        ManageTicketTypes::class => passType(),
        ManagePriceTiers::class => PriceTier::first(),
        ManageTicketPrices::class => TicketPrice::first(),
        ManageCapacityPools::class => CapacityPool::first(),
        ManageDiscountCodes::class => DiscountCode::factory()->create(),
        ManageUsers::class => auth()->user(),
    ] as $page => $record) {
        Livewire::test($page)->mountAction(CreateAction::class)->assertHasNoErrors()
            ->mountAction(TestAction::make(EditAction::class)->table($record))->assertHasNoErrors();
    }
    Livewire::test(ManageOrders::class)->mountAction(TestAction::make('view')->table($order))->assertSee($buyer->email)
        ->mountAction('issueComplimentary')->assertHasNoErrors();
    Livewire::test(ManageAuditLogs::class)
        ->mountAction(TestAction::make('view')->table(AuditLog::first()))->assertSee('test.smoke');
});
