<?php

// TicketType::onSale(): l'unica regola di «in vendita» (checkout, pagine pubbliche, prezzi mostrati, pannello).

use App\Enums\EventStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Catalog;
use App\Services\ShopException;

beforeEach(fn () => $this->travelTo('2026-12-01 10:00:00'));

it('è in vendita solo se attivo, con inizio passato, senza fine o non finito, evento pubblicato', function (array $type, array $event, bool $onSale) {
    $pass = passType();
    $pass->update($type);
    $pass->event->update($event);

    expect($pass->refresh()->isOnSale())->toBe($onSale)
        ->and(TicketType::onSale()->whereKey($pass->id)->exists())->toBe($onSale)
        ->and(collect(app(Catalog::class)->for($pass->event, null)['passes'])->contains(fn ($row) => $row['type']->is($pass)))->toBe($onSale);
})->with([
    'in vendita' => [[], [], true],
    'senza data d\'inizio' => [['sales_start' => null], [], false],
    'inizio domani' => [['sales_start' => '2026-12-02 00:00:00'], [], false],
    'inizio adesso' => [['sales_start' => '2026-12-01 10:00:00'], [], true],
    'fine passata' => [['sales_end' => '2026-12-01 10:00:00'], [], false],
    'fine domani' => [['sales_end' => '2026-12-02 00:00:00'], [], true],
    'spento' => [['active' => false], [], false],
    'evento in bozza' => [[], ['status' => EventStatus::Draft], false],
    'vendite dell\'evento chiuse' => [[], ['sales_close_at' => '2026-11-30 00:00:00'], false],
]);

it('il checkout rifiuta un tipo senza data d\'inizio', function () {
    passType()->update(['sales_start' => null]);
    $buyer = User::factory()->create();

    expect(fn () => reserve($buyer, [line(passType(), $buyer)]))->toThrow(ShopException::class, 'not_available');
});
