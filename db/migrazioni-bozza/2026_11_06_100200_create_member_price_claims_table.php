<?php

// Bozza di migrazione Laravel. Autore: Giulio Occhipinti. Aggiornata al 06/11/2026.
//
// Limite prezzo membro (decisione di Giulio del 05/11/2026): al massimo un biglietto a prezzo membro per membro,
// per evento e per tipo (un pass intero; un giornaliero per ciascun giorno, se un giorno si venderanno; uno per
// ciascuna sessione a pagamento), anche su più ordini. Vale in ogni fascia: l'early bird del membro (276,50 €) è
// un prezzo membro e consuma la stessa prenotazione del prezzo membro regular.
// Scritta per MySQL 8.0.16+ / MariaDB 10.6+ (database principale dal 06/11/2026). Il ramo Postgres resta nella
// storia di git.
// Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e MariaDB 10.11.14 (db/modello-dati.md §6).
// Presuppone: users, events, event_days, program_sessions (le sessioni del programma: «sessions» è la tabella
// delle sessioni HTTP di Laravel), orders e order_items (migrazione 100000).
//
// Regole:
//   - DB: una sola prenotazione attiva per (membro, evento, tipo, giorno, sessione), anche con due checkout in
//     parallelo: il secondo insert aspetta il lock del primo sull'indice unico e poi fallisce (errore 1062), senza
//     lock espliciti.
//   - Service (App\Services\Orders\OrderService, nella stessa transazione delle righe d'ordine):
//       * crea la prenotazione per ogni riga member; l'errore di unicità diventa "member_price_limit" e la riga
//         passa a prezzo pubblico con una spiegazione all'utente;
//       * la libera (released_at) quando l'ordine diventa expired, failed, cancelled, needs_refund o refunded,
//         o quando il biglietto a prezzo membro diventa cancelled o refunded;
//       * al pagamento tardivo la riprende; se nel frattempo il membro ha già un biglietto membro dello stesso
//         tipo, il biglietto si emette lo stesso (soldi incassati) e lo staff riceve l'avviso
//         member_price_limit_late. L'insert va in una transazione annidata (savepoint), così l'errore si gestisce
//         senza perdere il resto della conferma.
//   - Accompagnatori, espositori e omaggi non hanno mai prezzo membro: nessuna prenotazione.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Migrazione scritta per MySQL 8.0.16+ / MariaDB 10.6+.');
        }

        Schema::create('member_price_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->string('kind', 20);  // full_pass, day_pass, paid_session
            // RESTRICT: MySQL vieta i CHECK (qui la forma della chiave) e le colonne generate (qui active_key) su
            // colonne con azioni referenziali CASCADE o SET NULL.
            $table->foreignUuid('event_day_id')->nullable()->constrained('event_days')->restrictOnDelete();
            $table->foreignUuid('program_session_id')->nullable()->constrained('program_sessions')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 200)->nullable();

            $table->index('order_id');

            // MySQL non ha indici parziali né NULLS NOT DISTINCT: colonna generata con la chiave della prenotazione
            // solo se attiva (NULL se rilasciata) e indice unico su quella. I NULL di giorno e sessione diventano
            // '-' così due pass interi dello stesso membro collidono. Cast a char(36) per MariaDB, dove le colonne
            // uuid di Laravel hanno il tipo nativo UUID e coalesce(uuid, '-') fallisce.
            $table->string('active_key', 200)->nullable()->storedAs(
                "case when released_at is null then concat_ws('|', user_id, event_id, kind, "
                ."coalesce(cast(event_day_id as char(36)), '-'), coalesce(cast(program_session_id as char(36)), '-')) end"
            );
            $table->unique('active_key', 'member_price_claims_one_active');
        });

        DB::statement("alter table member_price_claims add constraint member_price_claims_kind_shape check (
            (kind = 'full_pass'    and event_day_id is null     and program_session_id is null) or
            (kind = 'day_pass'     and event_day_id is not null and program_session_id is null) or
            (kind = 'paid_session' and event_day_id is null     and program_session_id is not null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('member_price_claims');
    }
};
