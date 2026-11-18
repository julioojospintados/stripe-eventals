<?php

// Eliminazione della configurazione: solo se non usata da ordini, biglietti, riserve o utilizzi; ordini e simili mai.

use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\Event;
use App\Models\Order;
use App\Models\PriceTier;
use App\Models\Room;
use App\Models\Ticket;
use App\Models\TicketPrice;
use App\Models\User;
use App\Services\ConfigDeletion;
use App\Services\DeletionBlocked;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->deletion = app(ConfigDeletion::class);
    $this->admin = User::factory()->staff(StaffRole::Admin)->create();
});

it('elimina un prezzo mai usato e lo scrive nel registro audit', function () {
    $price = TicketPrice::factory()->create(['ticket_type_id' => passType()->id, 'price_tier_id' => PriceTier::where('code', 'regular')->value('id')]);

    $this->deletion->delete($price, $this->admin);

    $log = AuditLog::where('action', 'config.deleted')->sole();
    expect(TicketPrice::find($price->id))->toBeNull()
        ->and($log->entity_id)->toBe($price->id)
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->data['type'])->toBe('TicketPrice');
});

it('rifiuta i dati usati da un ordine, con un messaggio leggibile', function () {
    $buyer = User::factory()->create();
    $order = reserve($buyer, [line(passType(), $buyer)]);
    $price = TicketPrice::find($order->items()->sole()->ticket_price_id);

    expect($this->deletion->blocker($price))->toBe('Usato da 1 ordine: disattivalo invece di eliminarlo.')
        ->and($this->deletion->blocker(passType()))->toBe('Usato da 1 ordine: disattivalo invece di eliminarlo.')
        ->and($this->deletion->blocker(CapacityPool::where('name', 'Day 1 (2027-04-08)')->sole()))->toBe('Usato da 1 riserva di posti: disattivalo invece di eliminarlo.')
        ->and($this->deletion->blocker(eas8()))->toStartWith('Usato da 1 ordine:');
    expect(fn () => $this->deletion->delete($price, $this->admin))->toThrow(DeletionBlocked::class);
    expect(TicketPrice::find($price->id))->not->toBeNull()
        ->and(AuditLog::where('action', 'config.deleted')->exists())->toBeFalse();
});

it('rifiuta la configurazione collegata ad altra configurazione', function () {
    $room = room();
    scheduledSession($room, '10:00', '11:00');

    expect($this->deletion->blocker(PriceTier::where('code', 'early_bird')->sole()))->toBe('Collegato a 1 prezzo: elimina o sposta prima quelli.')
        ->and($this->deletion->blocker($room))->toBe('Collegato a 1 sessione: elimina o sposta prima quelli.')
        ->and($this->deletion->blocker(passType()))->toBe('Collegato a 3 prezzi: elimina o sposta prima quelli.');
});

it('elimina un evento vuoto con i suoi giorni', function () {
    $event = Event::factory()->create();
    $event->days()->create(['day' => '2027-04-08']);

    $this->deletion->delete($event, $this->admin);

    expect(Event::find($event->id))->toBeNull()->and(DB::table('event_days')->where('event_id', $event->id)->exists())->toBeFalse();
});

it('le chiavi esterne restano l\'ultima difesa', function () {
    $buyer = User::factory()->create();
    $order = reserve($buyer, [line(passType(), $buyer)]);

    expect(fn () => TicketPrice::whereKey($order->items()->sole()->ticket_price_id)->delete())->toThrow(QueryException::class);
});

it('ordini, biglietti e staff non si eliminano mai', function () {
    $buyer = User::factory()->create();
    $order = reserve($buyer, [line(passType(), $buyer)]);

    expect($this->admin->can('delete', $order))->toBeFalse()
        ->and($this->admin->can('delete', Ticket::class))->toBeFalse()
        ->and($this->admin->can('delete', $buyer))->toBeFalse()
        ->and($this->admin->can('delete', Room::class))->toBeTrue()
        ->and(User::factory()->staff(StaffRole::Commerciale)->create()->can('delete', Room::class))->toBeFalse();
    expect(fn () => $this->deletion->delete($order, $this->admin))->toThrow(InvalidArgumentException::class);
    expect(Order::find($order->id))->not->toBeNull();
});
