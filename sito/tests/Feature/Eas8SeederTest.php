<?php

use App\Enums\PriceRole;
use App\Enums\TicketKind;
use App\Models\Event;
use App\Models\MembershipPlan;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('crea l\'EAS8 con giorni, capienze e fasce', function () {
    $event = Event::where('slug', 'eas8-lisbon-2027')->sole();

    expect($event->starts_on->toDateString())->toBe('2027-04-08')
        ->and($event->ends_on->toDateString())->toBe('2027-04-10')
        ->and($event->days)->toHaveCount(3)
        ->and($event->capacityPools()->where('capacity', 6000)->count())->toBe(3)
        ->and($event->priceTiers->pluck('active', 'code')->all())
        ->toBe(['early_bird' => true, 'regular' => true, 'last_minute' => false]);
});

it('mette i prezzi del pass e dei workshop in centesimi', function () {
    $event = Event::where('slug', 'eas8-lisbon-2027')->sole();
    $pass = $event->ticketTypes()->where('kind', TicketKind::FullPass)->sole();
    $prices = $pass->prices()->with('priceTier')->get()
        ->mapWithKeys(fn ($p) => [($p->priceTier->code ?? 'base').'/'.$p->price_role->value => $p->amount_cents]);

    expect($prices->sortKeys()->all())->toBe(['base/member' => 39500, 'base/public' => 69500, 'early_bird/member' => 27650]);

    $workshops = $event->ticketTypes()->where('kind', TicketKind::PaidSession)->with('prices', 'pools')->get();
    expect($workshops)->toHaveCount(10)
        ->and($workshops->every(fn ($w) => $w->prices->sole()->amount_cents === 10000
            && $w->prices->sole()->price_role === PriceRole::Public
            && $w->pools->sole()->capacity === 20))->toBeTrue();
});

it('lascia spenti e senza prezzo accompagnatore, espositore e omaggio', function () {
    $others = Event::where('slug', 'eas8-lisbon-2027')->sole()->ticketTypes()
        ->whereIn('kind', [TicketKind::Companion, TicketKind::Exhibitor, TicketKind::StaffPass])->withCount('prices')->get();

    expect($others)->toHaveCount(3)
        ->and($others->every(fn ($t) => ! $t->active && $t->prices_count === 0))->toBeTrue();
});

it('crea la membership annuale da 200 € e si può rilanciare', function () {
    $this->seed(DatabaseSeeder::class);

    expect(MembershipPlan::sole()->amount_cents)->toBe(20000)
        ->and(Event::count())->toBe(1);
});
