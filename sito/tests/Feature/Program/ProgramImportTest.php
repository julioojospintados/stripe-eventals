<?php

// Import CSV del programma (formato di contenuti/modelli): crea, aggiorna senza duplicare, salta le righe EXAMPLE-,
// errori riga per riga. Ogni riga passa dall'Importer di Filament come nel job della coda.

use App\Enums\ProgramSessionAccess;
use App\Enums\TicketKind;
use App\Filament\Imports\ProgramSessionImporter;
use App\Filament\Imports\RoomImporter;
use App\Filament\Imports\SessionPartImporter;
use App\Filament\Imports\SpeakerImporter;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\SessionPart;
use App\Models\Speaker;
use App\Models\Track;
use Filament\Actions\Testing\TestableImport;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

beforeEach(fn () => eas8());

/** Una riga importata con l'Importer indicato, per l'EAS8. */
function importRow(string $importer, array $row, array $options = []): TestableImport
{
    return $importer::test(options: ['event_id' => eas8()->id, ...$options])->import($row);
}

/** Righe di un modello di contenuti/modelli/, lette come le legge Filament (BOM tolto, intestazioni per nome). */
function templateRows(string $file): array
{
    return iterator_to_array(Reader::from(base_path("../contenuti/modelli/$file"))->setHeaderOffset(0)->getRecords(), false);
}

function sessionRow(array $change = []): array
{
    return [...templateRows('sessions.csv')[0], 'code' => 'ses-001', 'room_code' => 'room-01', 'track' => 'Clinical',
        'speaker_codes' => 'spk-001', 'moderator_codes' => 'spk-002', ...$change];
}

function importBasics(): void
{
    importRow(RoomImporter::class, ['code' => 'room-01', 'name_en' => 'Main Hall', 'name_it' => '', 'capacity' => '1200', 'floor' => '0', 'position' => '1'])->assertImported();
    foreach (templateRows('speakers.csv') as $i => $row) {
        importRow(SpeakerImporter::class, [...$row, 'code' => 'spk-00'.($i + 1), 'photo_file' => ''])->assertImported();
    }
}

it('salta le righe d\'esempio di tutti i modelli', function () {
    foreach (['rooms.csv' => RoomImporter::class, 'speakers.csv' => SpeakerImporter::class,
        'sessions.csv' => ProgramSessionImporter::class, 'session_parts.csv' => SessionPartImporter::class] as $file => $importer) {
        foreach (templateRows($file) as $row) {
            importRow($importer, $row)->assertSkipped();
        }
    }

    expect(Room::count() + Speaker::count() + SessionPart::count())->toBe(0)
        ->and(ProgramSession::count())->toBe(10);   // i workshop del seeder
});

it('crea sale, speaker, sessioni e interventi; il secondo import aggiorna senza duplicare', function () {
    importBasics();
    $part = [...templateRows('session_parts.csv')[0], 'session_code' => 'ses-001', 'speaker_codes' => 'spk-002|spk-001'];

    foreach ([1, 2] as $round) {
        importRow(ProgramSessionImporter::class, sessionRow(['title_en' => "Opening $round"]), ['create_tracks' => true])->assertImported();
        importRow(SessionPartImporter::class, $part)->assertImported();
    }
    importRow(RoomImporter::class, ['code' => 'room-01', 'name_en' => 'Auditorium', 'capacity' => '1000'])->assertImported();

    $session = ProgramSession::where('code', 'ses-001')->sole();
    expect(Room::count())->toBe(1)->and(Room::sole()->name)->toBe(['en' => 'Auditorium'])->and(Room::sole()->capacity)->toBe(1000)
        ->and(Speaker::count())->toBe(2)->and(Track::count())->toBe(1)->and(SessionPart::count())->toBe(1)
        ->and($session->title)->toBe(['en' => 'Opening 2', 'it' => 'ESEMPIO Lezione di apertura'])
        ->and($session->starts_at->format('Y-m-d H:i'))->toBe('2027-04-08 08:00')   // 09:00 a Lisbona
        ->and($session->eventDay->day->toDateString())->toBe('2027-04-08')
        ->and($session->published)->toBeFalse()
        ->and($session->sessionSpeakers->map(fn ($s) => [$s->speaker->code, $s->role->value])->all())
        ->toBe([['spk-001', 'speaker'], ['spk-002', 'moderator']])
        ->and($session->parts->sole()->speakers->pluck('code')->all())->toBe(['spk-002', 'spk-001'])
        ->and(Speaker::where('code', 'spk-001')->sole()->bio['it'])->toStartWith('ESEMPIO')
        ->and(Speaker::where('code', 'spk-001')->sole()->links)->toBe(['https://example.org/ana', 'https://example.com/profile']);
});

it('workshop: paid=yes crea una volta sola tipo di biglietto spento, posti e prezzo', function () {
    importBasics();
    $row = sessionRow(['code' => 'ws-01', 'format' => 'workshop', 'paid' => 'yes', 'seats' => '20', 'track' => '', 'moderator_codes' => '']);

    importRow(ProgramSessionImporter::class, $row)->assertImported();
    importRow(ProgramSessionImporter::class, [...$row, 'title_en' => 'Hands-on'])->assertImported();

    $session = ProgramSession::where('code', 'ws-01')->sole();
    $type = $session->ticketType;
    expect($session->access)->toBe(ProgramSessionAccess::Paid)
        ->and($type->kind)->toBe(TicketKind::PaidSession)->and($type->active)->toBeFalse()
        ->and($type->pools->sole()->capacity)->toBe(20)
        ->and($type->prices->sole()->amount_cents)->toBe(10000)
        ->and(eas8()->ticketTypes()->where('program_session_id', $session->id)->count())->toBe(1);

    importRow(ProgramSessionImporter::class, [...$row, 'paid' => 'no'])->assertHasRowFailure('This session is sold as a workshop: it cannot become paid=no from the file.');
});

it('segnala gli errori riga per riga senza salvare la riga', function () {
    importBasics();
    importRow(ProgramSessionImporter::class, sessionRow(), ['create_tracks' => true])->assertImported();

    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-002', 'start' => '10:00', 'end' => '11:00']))
        ->assertHasRowFailure('The room is already taken by "ses-001 · EXAMPLE Opening lecture" (08/04/2027, 09:00–10:30). Change the room or the times.');
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-003', 'room_code' => 'room-99']))
        ->assertHasRowFailure('Unknown room "room-99": import rooms.csv first.');
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-004', 'day' => '2027-05-01']))
        ->assertHasRowFailure('The day 2027-05-01 is outside the event dates.');
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-005', 'start' => '12:00', 'end' => '11:00']))
        ->assertHasRowFailure('The end time must be after the start time.');
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-006', 'track' => 'Nuova', 'start' => '14:00', 'end' => '15:00']))
        ->assertHasRowFailure();
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-007', 'speaker_codes' => 'spk-404', 'start' => '14:00', 'end' => '15:00']))
        ->assertHasRowFailure('Unknown speaker codes: spk-404. Import speakers.csv first.');
    importRow(ProgramSessionImporter::class, sessionRow(['code' => 'ses-008', 'title_en' => '', 'format' => 'party']))
        ->assertHasErrors(['title_en', 'format']);
    importRow(SessionPartImporter::class, ['session_code' => 'ses-001', 'position' => '1', 'start' => '08:00', 'end' => '09:30', 'title_en' => 'Too early'])
        ->assertHasRowFailure('The part times must be in order and within the session times.');
    importRow(SpeakerImporter::class, [...templateRows('speakers.csv')[0], 'code' => 'spk-009'])
        ->assertHasRowFailure();   // foto non copiata nella cartella privata

    expect(ProgramSession::where('code', 'like', 'ses-%')->pluck('code')->all())->toBe(['ses-001'])
        ->and(SessionPart::count())->toBe(0)->and(Speaker::where('code', 'spk-009')->exists())->toBeFalse();
});

it('pubblica la foto ricodificata e lascia l\'originale sul disco privato', function () {
    Storage::fake('local');
    Storage::fake('public');
    $image = imagecreatetruecolor(1200, 900);
    ob_start();
    imagepng($image);
    Storage::disk('local')->put('speaker-photos/spk-001.png', (string) ob_get_clean());

    importRow(SpeakerImporter::class, [...templateRows('speakers.csv')[0], 'code' => 'spk-001', 'photo_file' => 'spk-001.png'])->assertImported();

    $path = Speaker::sole()->photo_path;
    [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect($path)->toEndWith('.jpg')->and([$width, $height, $type])->toBe([800, 800, IMAGETYPE_JPEG])
        ->and(Storage::disk('local')->exists('speaker-photos/spk-001.png'))->toBeTrue();
});
