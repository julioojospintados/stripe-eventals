<?php

// Fase 2, programma e speaker (db/modello-dati.md §3.2). Autore: Giulio Occhipinti.
//
// Sale, tracce, sessioni complete (estende program_sessions della Fase 1), interventi dentro una sessione, speaker e
// chi parla in quale sessione o intervento. I workshop restano tipi di biglietto con capienza: il collegamento è
// ticket_types.program_session_id (migrazione 090000), qui non si duplica.
//
// Regole:
//   - DB: codici d'import unici e nel formato del foglio (lettere, cifre, -); fine dopo l'inizio; capienza > 0;
//     una sessione con sala ha sempre inizio e fine; formato e ruolo da elenco chiuso.
//   - TRG (facoltativo, come gli altri): due sessioni nella stessa sala non si sovrappongono (possono toccarsi).
//   - SRV (App\Services\ProgramService::save): stesso controllo con lockForUpdate sulla sala e messaggio leggibile,
//     giorno dell'evento ricavato dall'ora di inizio a Lisbona; vale anche dove i trigger mancano.
//   - Posizione di interventi e speaker senza indice unico: il riordino di Filament riscrive le posizioni una alla
//     volta e passerebbe da un doppione temporaneo. L'ordine è quello di position; l'import usa (sessione, position)
//     come chiave e quindi non crea doppioni.

use App\Database\CreatesTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use CreatesTriggers;

    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->string('code', 30);
            $table->json('name');
            $table->unsignedInteger('capacity');
            $table->smallInteger('floor')->nullable();      // 0 piano terra, 1 primo, -1 interrato
            $table->unsignedSmallInteger('position')->default(0);   // colonna nella griglia del programma
            $table->datetimes();

            $table->unique(['event_id', 'code']);
        });

        DB::statement("alter table rooms add constraint rooms_code_format check (code regexp '^[A-Za-z0-9-]{1,30}$')");
        DB::statement('alter table rooms add constraint rooms_capacity_positive check (capacity > 0)');

        Schema::create('tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            // Dal nome inglese: è la chiave della colonna track del foglio e del filtro pubblico.
            $table->string('slug', 100);
            $table->json('name');
            $table->char('color', 7)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->unique(['event_id', 'slug']);
        });

        DB::statement("alter table tracks add constraint tracks_color_hex check (color is null or color regexp '^#[0-9A-Fa-f]{6}$')");

        Schema::table('program_sessions', function (Blueprint $table) {
            $table->foreignUuid('room_id')->nullable()->after('event_day_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUuid('track_id')->nullable()->after('room_id')->constrained('tracks')->restrictOnDelete();
            $table->string('format', 20)->default('lecture')->after('abstract');

            $table->index(['room_id', 'starts_at']);
        });

        DB::table('program_sessions')->where('access', 'paid')->update(['format' => 'workshop']);
        DB::statement("alter table program_sessions add constraint program_sessions_code_format check (code regexp '^[A-Za-z0-9-]{1,30}$')");
        DB::statement("alter table program_sessions add constraint program_sessions_format_valid check (format in (
            'plenary', 'keynote', 'lecture', 'panel', 'workshop', 'symposium', 'course', 'break', 'social'))");
        // Senza orari la sovrapposizione non si può controllare.
        DB::statement('alter table program_sessions add constraint program_sessions_room_has_times check (
            room_id is null or (starts_at is not null and ends_at is not null))');

        foreach (['insert', 'update'] as $op) {
            $this->trigger(<<<SQL
                create trigger program_sessions_no_room_overlap_{$op} before {$op} on program_sessions
                for each row
                begin
                  if new.room_id is not null and exists (select 1 from program_sessions s
                             where s.room_id = new.room_id and s.id <> new.id
                               and s.starts_at < new.ends_at and new.starts_at < s.ends_at) then
                    signal sqlstate '45000' set message_text = 'program_session_room_overlap';
                  end if;
                end
                SQL);
        }

        Schema::create('session_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_session_id')->constrained('program_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->json('title');
            $table->json('abstract')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->datetimes();

            $table->index(['program_session_id', 'position']);
        });

        DB::statement('alter table session_parts add constraint session_parts_times_valid check (
            starts_at is null or ends_at is null or ends_at > starts_at)');

        // Senza event_id: lo stesso speaker può tornare in altre edizioni e nei video.
        Schema::create('speakers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('slug', 150)->unique();
            $table->string('honorific', 20)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('affiliation', 200)->nullable();
            $table->char('country', 2)->nullable();   // ISO 3166-1 alpha-2
            $table->json('bio')->nullable();           // testo semplice tradotto
            $table->string('photo_path')->nullable();   // disco public, già ricodificata senza EXIF
            $table->string('website', 500)->nullable();
            $table->json('links')->nullable();          // altri profili pubblici, in ordine
            $table->string('email')->nullable();        // privata: biglietto omaggio e contatti, mai pubblicata
            $table->datetimes();

            $table->index(['last_name', 'first_name']);
        });

        DB::statement("alter table speakers add constraint speakers_code_format check (code regexp '^[A-Za-z0-9-]{1,30}$')");
        DB::statement("alter table speakers add constraint speakers_country_format check (country is null or country regexp '^[A-Za-z]{2}$')");

        Schema::create('session_speaker', function (Blueprint $table) {
            $table->uuid('id')->primary();   // serve al repeater di Filament
            $table->foreignUuid('program_session_id')->constrained('program_sessions')->cascadeOnDelete();
            $table->foreignUuid('speaker_id')->constrained('speakers')->restrictOnDelete();
            $table->string('role', 20)->default('speaker');
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->unique(['program_session_id', 'speaker_id']);
            $table->index('speaker_id');
        });

        DB::statement("alter table session_speaker add constraint session_speaker_role_valid check (role in ('speaker', 'moderator'))");

        Schema::create('session_part_speaker', function (Blueprint $table) {
            $table->foreignUuid('session_part_id')->constrained('session_parts')->cascadeOnDelete();
            $table->foreignUuid('speaker_id')->constrained('speakers')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['session_part_id', 'speaker_id']);
            $table->index('speaker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_part_speaker');
        Schema::dropIfExists('session_speaker');
        Schema::dropIfExists('speakers');
        Schema::dropIfExists('session_parts');
        DB::unprepared('drop trigger if exists program_sessions_no_room_overlap_insert');
        DB::unprepared('drop trigger if exists program_sessions_no_room_overlap_update');
        DB::statement('alter table program_sessions drop constraint program_sessions_room_has_times');
        DB::statement('alter table program_sessions drop constraint program_sessions_format_valid');
        DB::statement('alter table program_sessions drop constraint program_sessions_code_format');
        Schema::table('program_sessions', function (Blueprint $table) {
            $table->dropForeign(['track_id']);
            $table->dropForeign(['room_id']);
            $table->dropIndex(['room_id', 'starts_at']);
            $table->dropColumn(['room_id', 'track_id', 'format']);
        });
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('rooms');
    }
};
