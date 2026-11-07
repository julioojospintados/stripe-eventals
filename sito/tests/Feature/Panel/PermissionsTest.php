<?php

// Permessi granulari: matrice ruolo × azione (docs/architettura.md §5.3), seeder rilanciabile, ruoli e permessi nel
// pannello, «Resend ticket» e prezzo della membership.

use App\Enums\StaffRole;
use App\Filament\Resources\MembershipPlans\Pages\ManageMembershipPlans;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Tickets\Pages\ManageTickets;
use App\Jobs\SendTicketsEmail;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PriceTier;
use App\Models\Ticket;
use App\Models\TicketPrice;
use App\Models\TicketType;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    eas8();
});

function paidTicket(): Ticket
{
    $buyer = User::factory()->create();
    $order = reserveAndCheckout($buyer, [line(passType(), $buyer)]);
    app(PaymentService::class)->markPaid($order, test()->stripe->pay($order->stripe_checkout_session_id));

    return $order->tickets()->sole();
}

// [ability, model, admin, commerciale, organizzatore]: scritta a mano da §5.3, non dall'enum.
$crud = fn (string $model, bool $commercialeWrites, bool $organizzatoreWrites) => [
    "viewAny $model" => ['viewAny', $model, true, true, true],
    "create $model" => ['create', $model, true, $commercialeWrites, $organizzatoreWrites],
    "update $model" => ['update', $model, true, $commercialeWrites, $organizzatoreWrites],
    "delete $model" => ['delete', $model, false, false, false],
];
$matrix = [
    ...$crud(Event::class, false, true),
    ...$crud(TicketType::class, false, true),
    ...$crud(PriceTier::class, false, true),
    ...$crud(TicketPrice::class, false, true),
    ...$crud(CapacityPool::class, false, true),
    ...$crud(DiscountCode::class, true, true),
    'viewAny Order' => ['viewAny', Order::class, true, true, true],
    'refund' => ['refund', Order::class, true, true, false],
    'issueComplimentary' => ['issueComplimentary', Order::class, true, false, true],
    'review' => ['review', Order::class, true, true, false],
    'reconcile' => ['reconcile', Order::class, true, true, false],
    'viewAny Ticket' => ['viewAny', Ticket::class, true, true, true],
    'correctAttendee' => ['correctAttendee', Ticket::class, true, false, false],
    'resend' => ['resend', Ticket::class, true, true, false],
    'viewAny Membership' => ['viewAny', Membership::class, true, true, false],
    'viewAny MembershipPlan' => ['viewAny', MembershipPlan::class, true, true, false],
    'update MembershipPlan' => ['update', MembershipPlan::class, true, false, false],
    'viewAny Dispute' => ['viewAny', Dispute::class, true, true, false],
    'viewAny AuditLog' => ['viewAny', AuditLog::class, true, false, false],
    'viewAny User' => ['viewAny', User::class, true, false, false],
    'create User' => ['create', User::class, true, false, false],
    'update User' => ['update', User::class, true, false, false],
    'delete User' => ['delete', User::class, false, false, false],
    'viewAny Role' => ['viewAny', Role::class, true, false, false],
    'update Role organizzatore' => ['update', 'role:organizzatore', true, false, false],
    'update Role admin' => ['update', 'role:admin', false, false, false],
    'orders.view_amounts' => ['orders.view_amounts', null, true, true, false],
    'orders.export' => ['orders.export', null, true, true, false],
    'attendees.export' => ['attendees.export', null, true, true, true],
    'consents.export' => ['consents.export', null, true, true, false],
];

it('ogni ruolo può solo le azioni di §5.3', function (string $ability, ?string $model, bool ...$expected) {
    foreach (StaffRole::cases() as $i => $role) {
        $user = User::factory()->staff($role)->create();
        $argument = match (true) {
            $model === null => [],
            str_starts_with($model, 'role:') => Role::findByName(substr($model, 5)),
            default => $model,
        };
        expect($user->can($ability, $argument))->toBe($expected[$i], "$ability per {$role->value}");
    }
})->with($matrix);

it('i ruoli hanno esattamente i permessi predefiniti, nessuno può eliminare', function (StaffRole $role) {
    $granted = Role::findByName($role->value)->permissions->pluck('name');

    foreach (array_keys(StaffRole::allPermissions()) as $permission) {
        expect($granted->contains($permission))->toBe(in_array($permission, $role->permissions(), true), "$permission per {$role->value}");
    }
    expect($granted->filter(fn ($p) => str_ends_with($p, '.delete')))->toBeEmpty();
})->with(StaffRole::cases());

it('il seeder si rilancia: non duplica, toglie i permessi vecchi, dà i nuovi e tiene le modifiche dell\'admin', function () {
    $organizzatore = Role::findByName('organizzatore');
    $organizzatore->revokePermissionTo('event.update');   // modifica fatta dall'admin nel pannello
    Permission::findOrCreate('sales.manage')->assignRole($organizzatore);   // permesso che non esiste più
    Permission::findByName('tickets.resend')->delete();   // permesso nuovo per questo database

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(count(StaffRole::allPermissions()))
        ->and(Role::count())->toBe(3)
        ->and(Permission::where('name', 'sales.manage')->exists())->toBeFalse()
        ->and($organizzatore->fresh()->hasPermissionTo('event.update'))->toBeFalse()
        ->and(Role::findByName('commerciale')->hasPermissionTo('tickets.resend'))->toBeTrue()
        ->and(Role::findByName('organizzatore')->hasPermissionTo('tickets.resend'))->toBeFalse()
        ->and(Role::findByName('admin')->permissions()->count())->toBe(count(StaffRole::Admin->permissions()));
});

it('apre ruoli, contestazioni e prezzo della membership solo a chi ha il permesso', function (StaffRole $role) {
    $this->actingAs(User::factory()->staff($role)->create());
    $expected = [
        'admin' => ['roles' => 200, 'disputes' => 200, 'membership-plans' => 200],
        'commerciale' => ['roles' => 403, 'disputes' => 200, 'membership-plans' => 200],
        'organizzatore' => ['roles' => 403, 'disputes' => 403, 'membership-plans' => 403],
    ][$role->value];

    foreach ($expected as $page => $status) {
        expect($this->get("/admin/$page")->status())->toBe($status, "/admin/$page per {$role->value}");
    }
})->with(StaffRole::cases());

it('l\'admin cambia i permessi di un ruolo con audit; il ruolo admin non si modifica', function () {
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create());
    $organizzatore = Role::findByName('organizzatore');
    $before = $organizzatore->permissions->pluck('id')->all();
    $resend = Permission::findByName('tickets.resend');

    Livewire::test(ManageRoles::class)
        ->assertActionHidden(TestAction::make(EditAction::class)->table(Role::findByName('admin')))
        ->callAction(TestAction::make(EditAction::class)->table($organizzatore), data: ['permissions' => [...$before, $resend->id]])
        ->assertHasNoActionErrors();

    $log = AuditLog::where('action', 'role.permissions_updated')->sole();
    expect(User::factory()->staff(StaffRole::Organizzatore)->create()->can('tickets.resend'))->toBeTrue()
        ->and($log->data['added'])->toBe(['tickets.resend'])
        ->and($log->data['removed'])->toBe([]);
});

it('Resend ticket dal pannello: in coda all\'indirizzo scelto, con audit; nascosto a chi non ha il permesso', function () {
    Queue::fake([SendTicketsEmail::class]);
    $ticket = paidTicket();

    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
    Livewire::test(ManageTickets::class)->assertActionHidden(TestAction::make('resend')->table($ticket));

    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    Livewire::test(ManageTickets::class)
        ->callAction(TestAction::make('resend')->table($ticket), data: ['to' => 'Altro@Example.test'])
        ->assertHasNoActionErrors();

    Queue::assertPushed(SendTicketsEmail::class, fn ($job) => $job->to === 'altro@example.test');
    expect(AuditLog::where('action', 'ticket.resent')->sole()->data)->toBe(['to' => 'altro@example.test']);
});

it('il comando eas:resend-ticket usa lo stesso codice', function () {
    Queue::fake([SendTicketsEmail::class]);
    $ticket = paidTicket();

    $this->artisan('eas:resend-ticket', ['ticket' => $ticket->short_code])->assertSuccessful();

    Queue::assertPushed(SendTicketsEmail::class, fn ($job) => $job->to === $ticket->attendee_email);
});

it('l\'admin cambia prezzo e Price ID della membership: il Checkout usa il nuovo Price', function () {
    $plan = MembershipPlan::sole();

    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    Livewire::test(ManageMembershipPlans::class)->assertActionHidden(TestAction::make(EditAction::class)->table($plan));

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create());
    Livewire::test(ManageMembershipPlans::class)
        ->callAction(TestAction::make(EditAction::class)->table($plan), data: ['amount_cents' => 'x', 'stripe_price_id' => 'wrong'])
        ->assertHasActionErrors(['amount_cents', 'stripe_price_id']);
    Livewire::test(ManageMembershipPlans::class)
        ->callAction(TestAction::make(EditAction::class)->table($plan), data: ['amount_cents' => 220, 'stripe_price_id' => 'price_New123'])
        ->assertHasNoActionErrors();

    expect($plan->refresh()->only('amount_cents', 'stripe_price_id'))->toBe(['amount_cents' => 22000, 'stripe_price_id' => 'price_New123'])
        ->and(AuditLog::where('action', 'membership_plan.updated')->count())->toBe(1);

    $this->actingAs(User::factory()->create())->post('/membership');
    expect($this->stripe->callsTo('createCheckoutSession')[0][1]['line_items'][0]['price'])->toBe('price_New123');
});

it('un biglietto non valido non si rimanda: azione nascosta nel pannello, servizio e comando rifiutano', function () {
    Queue::fake([SendTicketsEmail::class]);
    $ticket = paidTicket();
    $ticket->update(['status' => \App\Enums\TicketStatus::Disputed]);
    $sent = Queue::pushed(SendTicketsEmail::class)->count();   // le email dell'acquisto

    $this->actingAs(User::factory()->staff(StaffRole::Commerciale)->create());
    Livewire::test(ManageTickets::class)->assertActionHidden(TestAction::make('resend')->table($ticket));
    expect(fn () => app(\App\Services\TicketService::class)->resend($ticket))->toThrow(\App\Services\ShopException::class, 'ticket_not_valid');
    $this->artisan('eas:resend-ticket', ['ticket' => $ticket->short_code])->assertFailed();

    Queue::assertPushed(SendTicketsEmail::class, $sent);
    expect(AuditLog::where('action', 'ticket.resent')->count())->toBe(0);
});
