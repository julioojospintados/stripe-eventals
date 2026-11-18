<?php

// Percorso completo dal pannello (prova di Simone, 18/11/2026): l'admin configura un evento da zero, le vendite si
// aprono solo con la data d'inizio, un cliente e un membro comprano, un prezzo usato non si elimina.

use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Enums\StaffRole;
use App\Filament\Resources\CapacityPools\Pages\ManageCapacityPools;
use App\Filament\Resources\Events\Pages\ManageEvents;
use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\TicketPrices\Pages\ManageTicketPrices;
use App\Filament\Resources\TicketTypes\Pages\ManageTicketTypes;
use App\Filament\Widgets\PricesToday;
use App\Models\AuditLog;
use App\Models\CapacityPool;
use App\Models\Event;
use App\Models\PriceTier;
use App\Models\TicketPrice;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\ShopException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-12-01 10:00:00');
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->create());
});

function buy(User $buyer, Event $event, TicketType $type)
{
    $checkout = app(CheckoutService::class);
    $order = $checkout->reserve($buyer, $event, [line($type, $buyer)]);
    $checkout->startCheckout($order);
    app(PaymentService::class)->markPaid($order, test()->stripe->pay($order->refresh()->stripe_checkout_session_id));

    return $order->refresh();
}

it('dall\'evento vuoto alla vendita: prezzo pubblico, early bird per i membri, eliminazione solo se non usato', function () {
    Livewire::test(ManageEvents::class)->callAction(CreateAction::class, data: [
        'title' => ['en' => 'Journey congress'], 'slug' => 'journey-2027', 'status' => 'published',
        'starts_on' => '2027-05-10', 'ends_on' => '2027-05-11', 'timezone' => 'Europe/Lisbon', 'currency' => 'eur',
        'days' => [['day' => '2027-05-10', 'label' => ['en' => 'Day 1']]],
    ])->assertHasNoActionErrors();
    $event = Event::where('slug', 'journey-2027')->sole();

    Livewire::test(ManageCapacityPools::class)->callAction(CreateAction::class, data: [
        'event_id' => $event->id, 'name' => 'Hall', 'capacity' => 100,
    ])->assertHasNoActionErrors();
    $pool = CapacityPool::where('event_id', $event->id)->sole();

    Livewire::test(ManageTicketTypes::class)->callAction(CreateAction::class, data: [
        'event_id' => $event->id, 'kind' => 'full_pass', 'name' => ['en' => 'Journey pass'], 'channel' => 'public',
        'max_per_order' => 4, 'position' => 1, 'active' => true, 'pools' => [$pool->id],
    ])->assertHasNoActionErrors();
    $type = TicketType::where('event_id', $event->id)->sole();

    Livewire::test(ManagePriceTiers::class)->callAction(CreateAction::class, data: [
        'event_id' => $event->id, 'code' => 'early_bird', 'name' => ['en' => 'Early bird'],
        'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2027-01-01 00:00:00', 'active' => true, 'position' => 1,
    ])->assertHasNoActionErrors();
    $tier = PriceTier::where('event_id', $event->id)->sole();

    $price = fn (array $data) => Livewire::test(ManageTicketPrices::class)
        ->callAction(CreateAction::class, data: ['ticket_type_id' => $type->id, ...$data])->assertHasNoActionErrors();
    $price(['price_role' => 'public', 'amount_cents' => 500]);
    $price(['price_role' => 'member', 'price_tier_id' => $tier->id, 'amount_cents' => 300]);
    $price(['price_role' => 'member', 'amount_cents' => 400]);   // prezzo membro base: con l'early bird attiva non si usa

    // Senza data d'inizio non è in vendita: né al checkout né nella tabella «Prezzo oggi».
    expect($type->refresh()->isOnSale())->toBeFalse();
    expect(fn () => app(CheckoutService::class)->reserve(User::factory()->create(), $event, [line($type, User::factory()->create())]))
        ->toThrow(ShopException::class, 'not_available');
    Livewire::test(PricesToday::class)->assertSee('Journey pass')->assertSee("no: senza data d'inizio")
        ->assertSee('Early bird fino al 01/01/2027 00:00');

    Livewire::test(ManageTicketTypes::class)
        ->callAction(TestAction::make(EditAction::class)->table($type), data: ['sales_start' => '2026-11-15 00:00:00'])
        ->assertHasNoActionErrors();
    expect($type->refresh()->isOnSale())->toBeTrue();

    $public = buy(User::factory()->create(), $event, $type);
    $member = buy(member(), $event, $type);

    expect($public->status)->toBe(OrderStatus::Paid)
        ->and($public->total_cents)->toBe(50000)
        ->and($public->price_role)->toBe(PriceRole::Public)
        ->and($member->status)->toBe(OrderStatus::Paid)
        ->and($member->total_cents)->toBe(30000)
        ->and($member->price_role)->toBe(PriceRole::Member)
        ->and($member->price_tier_id)->toBe($tier->id);

    // Il prezzo membro base non l'ha usato nessuno: si elimina, con audit.
    $unused = TicketPrice::where('ticket_type_id', $type->id)->where('price_role', 'member')->whereNull('price_tier_id')->sole();
    Livewire::test(ManageTicketPrices::class)->callAction(TestAction::make(DeleteAction::class)->table($unused))
        ->assertNotified('Deleted');
    expect(TicketPrice::find($unused->id))->toBeNull()
        ->and(AuditLog::where('action', 'config.deleted')->where('entity_id', $unused->id)->exists())->toBeTrue();

    // Il prezzo pubblico è sull'ordine del cliente: rifiutato, con il motivo; anche in massa.
    $used = TicketPrice::where('ticket_type_id', $type->id)->where('price_role', 'public')->sole();
    Livewire::test(ManageTicketPrices::class)->callAction(TestAction::make(DeleteAction::class)->table($used))
        ->assertNotified(Notification::make()->danger()->title('Non eliminato')
            ->body('Usato da 1 ordine: disattivalo invece di eliminarlo.')->persistent());
    Livewire::test(ManageTicketPrices::class)->selectTableRecords([$used->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified();
    expect(TicketPrice::find($used->id))->not->toBeNull();
});
