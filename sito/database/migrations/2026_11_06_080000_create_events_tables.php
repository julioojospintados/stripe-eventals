<?php

// Evento, giorni e sessioni del programma (versione minima della Fase 1: i workshop a pagamento).
// MySQL 8.0.16+ / MariaDB 10.7+ (db/modello-dati.md §1). Sale, tracce, speaker e capienza delle sessioni
// arrivano con il programma completo in Fase 2.
// Le sessioni del programma si chiamano program_sessions: "sessions" è la tabella delle sessioni HTTP di Laravel.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I vincoli (CHECK, colonne generate, trigger) sono scritti per MySQL e MariaDB: niente SQLite.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Migrazioni scritte per MySQL 8.0.16+ / MariaDB 10.7+.');
        }

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            // Testi tradotti: {"en": "...", "it": "..."}.
            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('description')->nullable();
            $table->string('status', 20)->default('draft');
            // Fuso dell'evento, solo per mostrare le ore: nel database tutto è in UTC.
            $table->string('timezone', 64)->default('Europe/Lisbon');
            // Date modificabili dal pannello (risposta del cliente).
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('venue_name', 200)->nullable();
            $table->string('venue_address', 500)->nullable();
            $table->char('currency', 3)->default('eur');
            $table->dateTime('sales_open_at')->nullable();
            $table->dateTime('sales_close_at')->nullable();
            $table->datetimes();
        });

        DB::statement("alter table events add constraint events_status_valid check (
            status in ('draft', 'published', 'cancelled', 'archived'))");
        DB::statement('alter table events add constraint events_dates_valid check (ends_on >= starts_on)');
        DB::statement('alter table events add constraint events_sales_window check (
            sales_open_at is null or sales_close_at is null or sales_close_at > sales_open_at)');
        DB::statement('alter table events add constraint events_currency_lower check (currency = lower(currency))');

        Schema::create('event_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->date('day');
            $table->json('label')->nullable();
            $table->datetimes();

            $table->unique(['event_id', 'day']);
        });

        Schema::create('program_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            // Chiave del foglio di import (Fase 2), es. W01.
            $table->string('code', 30);
            $table->foreignUuid('event_day_id')->nullable()->constrained('event_days')->restrictOnDelete();
            $table->string('slug', 150);
            $table->json('title');
            $table->json('abstract')->nullable();
            // included: compresa nel pass; included_booking: compresa ma da prenotare; paid: workshop a pagamento.
            $table->string('access', 20)->default('included');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('published')->default(false);
            $table->datetimes();

            $table->unique(['event_id', 'slug']);
            $table->unique(['event_id', 'code']);
        });

        DB::statement("alter table program_sessions add constraint program_sessions_access_valid check (
            access in ('included', 'included_booking', 'paid'))");
        DB::statement('alter table program_sessions add constraint program_sessions_times_valid check (
            starts_at is null or ends_at is null or ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('program_sessions');
        Schema::dropIfExists('event_days');
        Schema::dropIfExists('events');
    }
};
