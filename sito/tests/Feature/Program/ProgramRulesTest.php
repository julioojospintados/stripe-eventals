<?php

// Programma: vincoli del database, sale sovrapposte (trigger + ProgramService) ed errore leggibile nel pannello.

use App\Enums\StaffRole;
use App\Filament\Resources\ProgramSessions\Pages\CreateProgramSession;
use App\Filament\Resources\ProgramSessions\Pages\EditProgramSession;
use App\Models\ProgramSession;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ProgramException;
use App\Services\ProgramService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => eas8());

describe('database', function () {
    it('rifiuta una fine non dopo l\'inizio e una sala senza orari', function () {
        $room = room();
        $base = ['event_id' => $room->event_id, 'slug' => 'x', 'title' => ['en' => 'X']];

        expect(fn () => ProgramSession::create($base + ['code' => 'a1', 'starts_at' => '2027-04-08 10:00', 'ends_at' => '2027-04-08 10:00']))
            ->toThrow(QueryException::class, 'program_sessions_times_valid')
            ->and(fn () => ProgramSession::create($base + ['code' => 'a2', 'room_id' => $room->id]))
            ->toThrow(QueryException::class, 'program_sessions_room_has_times');
    });

    it('rifiuta codici doppi, codici fuori formato e capienza zero', function () {
        $room = room(['code' => 'room-01']);
        Speaker::factory()->create(['code' => 'spk-001']);

        expect(fn () => room(['code' => 'room-01']))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => Speaker::factory()->create(['code' => 'spk-001']))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => room(['code' => 'room 2']))->toThrow(QueryException::class, 'rooms_code_format')
            ->and(fn () => room(['capacity' => 0]))->toThrow(QueryException::class, 'rooms_capacity_positive');

        scheduledSession($room, '09:00', '10:00', ['code' => 'ses-1']);
        expect(fn () => scheduledSession(room(), '09:00', '10:00', ['code' => 'ses-1']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('il trigger rifiuta due sessioni sovrapposte nella stessa sala anche senza il servizio', function () {
        requireTrigger('program_sessions_no_room_overlap_insert');
        $room = room();
        scheduledSession($room, '09:00', '10:30');

        expect(fn () => ProgramSession::create([
            'event_id' => $room->event_id, 'code' => 'raw', 'slug' => 'raw', 'title' => ['en' => 'Raw'], 'room_id' => $room->id,
            'starts_at' => Carbon::parse('2027-04-08 10:00', 'Europe/Lisbon')->utc(), 'ends_at' => Carbon::parse('2027-04-08 11:00', 'Europe/Lisbon')->utc(),
        ]))->toThrow(QueryException::class, 'program_session_room_overlap');
    });
});

describe('ProgramService', function () {
    it('rifiuta la sala occupata con un messaggio che nomina l\'altra sessione', function () {
        $room = room();
        scheduledSession($room, '09:00', '10:30', ['code' => 'ses-a', 'title' => ['en' => 'Opening']]);

        expect(fn () => scheduledSession($room, '10:00', '11:00'))
            ->toThrow(ProgramException::class, 'The room is already taken by "ses-a · Opening" (08/04/2027, 09:00–10:30)');
    });

    it('accetta sessioni che si toccano, altre sale, altri giorni e il nuovo salvataggio della stessa sessione', function () {
        $room = room();
        $first = scheduledSession($room, '09:00', '10:30');
        scheduledSession($room, '10:30', '11:00');
        scheduledSession(room(), '09:00', '10:30');
        $other = scheduledSession($room, '09:00', '10:30', day: '2027-04-09');
        $first->title = ['en' => 'Renamed'];

        app(ProgramService::class)->save($first);

        expect(ProgramSession::count())->toBe(14)   // 10 workshop del seeder + 4
            ->and($first->eventDay->day->toDateString())->toBe('2027-04-08')
            ->and($other->eventDay->day->toDateString())->toBe('2027-04-09')
            ->and($first->starts_at->format('H:i'))->toBe('08:00');   // 09:00 a Lisbona = 08:00 UTC ad aprile
    });

    it('rifiuta un giorno fuori dalle date dell\'evento', function () {
        expect(fn () => scheduledSession(room(), '09:00', '10:00', day: '2027-04-11'))
            ->toThrow(ProgramException::class, 'outside the event dates');
    });
});

describe('pannello', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->staff(StaffRole::Organizzatore)->create());
    });

    it('dà un errore leggibile se la sala è occupata e salva con speaker e interventi in ordine', function () {
        $room = room();
        scheduledSession($room, '09:00', '10:30', ['code' => 'ses-a']);
        [$ana, $bruno] = Speaker::factory()->count(2)->create();
        $form = [
            'event_id' => $room->event_id, 'code' => 'ses-b', 'title' => ['en' => 'Panel'], 'format' => 'panel',
            'access' => 'included', 'room_id' => $room->id,
            'starts_at' => '2027-04-08 10:00:00', 'ends_at' => '2027-04-08 11:00:00',
        ];

        Livewire::test(CreateProgramSession::class)->fillForm($form)->call('create')
            ->assertHasFormErrors(['ends_at'])->assertSee('ses-a');

        Livewire::test(CreateProgramSession::class)
            ->fillForm([...$form, 'starts_at' => '2027-04-08 10:30:00',
                'sessionSpeakers' => [['speaker_id' => $bruno->id, 'role' => 'moderator'], ['speaker_id' => $ana->id, 'role' => 'speaker']],
                'parts' => [['title' => ['en' => 'First'], 'speakers' => [$ana->id]], ['title' => ['en' => 'Second']]],
            ])
            ->call('create')->assertHasNoFormErrors();

        $session = ProgramSession::where('code', 'ses-b')->sole();
        expect($session->speakers->pluck('id')->all())->toBe([$bruno->id, $ana->id])
            ->and($session->parts->pluck('title.en')->all())->toBe(['First', 'Second'])
            ->and($session->parts->first()->speakers->pluck('id')->all())->toBe([$ana->id]);

        $this->get("/admin/program-sessions/{$session->id}/edit")->assertOk();
        Livewire::test(EditProgramSession::class, ['record' => $session->id])
            ->fillForm(['starts_at' => '2027-04-08 09:30:00'])->call('save')->assertHasFormErrors(['ends_at']);
    });
});
