<?php

// Controlli di sovrapposizione nel pannello con l'ora legale di Lisbona (UTC+1): fasce di prezzo e sale del programma.

use App\Enums\StaffRole;
use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Filament\Resources\ProgramSessions\Pages\CreateProgramSession;
use App\Models\PriceTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->event = eas8();
    $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
});

describe('fasce di prezzo', function () {
    beforeEach(function () {
        PriceTier::query()->update(['active' => false]);
        $lisbon = fn (string $time) => Carbon::parse($time, 'Europe/Lisbon')->utc();
        PriceTier::factory()->create([
            'event_id' => $this->event->id, 'code' => 'june', 'active' => true,
            'starts_at' => $lisbon('2027-06-01 00:00'), 'ends_at' => $lisbon('2027-06-15 23:59'),
        ]);
        $this->createTier = fn (string $from, string $to) => Livewire::test(ManagePriceTiers::class)
            ->callAction(CreateAction::class, data: [
                'event_id' => $this->event->id, 'code' => 'flash', 'name' => ['en' => 'Flash'],
                'starts_at' => $from, 'ends_at' => $to, 'active' => true, 'position' => 9,
            ]);
    });

    it('accetta una fascia che inizia a mezzanotte dopo una che finisce alle 23:59', function () {
        ($this->createTier)('2027-06-16 00:00:00', '2027-06-30 00:00:00')->assertHasNoActionErrors();
    });

    it('rifiuta una fascia che inizia 30 minuti prima della fine dell\'altra', function () {
        ($this->createTier)('2027-06-15 23:29:00', '2027-06-30 00:00:00')->assertHasActionErrors(['ends_at']);
    });

    it('rifiuta una fascia che finisce 30 minuti dopo l\'inizio dell\'altra', function () {
        ($this->createTier)('2027-05-20 00:00:00', '2027-06-01 00:30:00')->assertHasActionErrors(['ends_at']);
    });
});

describe('sale del programma', function () {
    beforeEach(function () {
        $this->room = room();
        scheduledSession($this->room, '23:00', '23:59');
        $this->createSession = fn (string $from, string $to) => Livewire::test(CreateProgramSession::class)
            ->fillForm([
                'event_id' => $this->event->id, 'code' => 'ses-new', 'title' => ['en' => 'New'], 'format' => 'panel',
                'access' => 'included', 'room_id' => $this->room->id, 'starts_at' => $from, 'ends_at' => $to,
            ])->call('create');
    });

    it('accetta una sessione che inizia quando finisce l\'altra', function () {
        ($this->createSession)('2027-04-08 23:59:00', '2027-04-09 00:30:00')->assertHasNoFormErrors();
    });

    it('rifiuta una sessione che si sovrappone di 30 minuti, prima o dopo', function () {
        ($this->createSession)('2027-04-08 23:29:00', '2027-04-09 00:30:00')->assertHasFormErrors(['ends_at']);
        ($this->createSession)('2027-04-08 22:30:00', '2027-04-08 23:30:00')->assertHasFormErrors(['ends_at']);
    });
});
