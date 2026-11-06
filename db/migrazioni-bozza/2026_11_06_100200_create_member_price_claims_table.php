<?php

// Bozza di migrazione Laravel. Autore: Giulio Occhipinti. Aggiornata al 06/11/2026.
//
// Limite prezzo membro (decisione di Giulio del 05/11/2026): al massimo un biglietto a prezzo membro per membro,
// per evento e per tipo (un pass intero; un giornaliero per ciascun giorno, se un giorno si venderanno; uno per
// ciascuna sessione a pagamento), anche su più ordini.
// Scritta per Postgres 16+ (NULLS NOT DISTINCT richiede Postgres 15+). Ramo MySQL indicato ma non provato.
// Presuppone: users, events, event_days, sessions, orders e order_items (migrazione 100000).
//
// Regole:
//   - DB: una sola prenotazione attiva per (membro, evento, tipo, giorno, sessione), anche con due checkout in
//     parallelo: il secondo insert aspetta il primo e poi fallisce sull'indice unico, senza lock espliciti.
//   - Service (App\Services\Orders\OrderService, nella stessa transazione delle righe d'ordine):
//       * crea la prenotazione per ogni riga member; l'errore di unicità diventa "member_price_limit" e la riga
//         passa a prezzo pubblico con una spiegazione all'utente;
//       * la libera (released_at) quando l'ordine diventa expired, failed, cancelled, needs_refund o refunded,
//         o quando il biglietto a prezzo membro diventa cancelled o refunded;
//       * al pagamento tardivo la riprende; se nel frattempo il membro ha già un biglietto membro dello stesso
//         tipo, il biglietto si emette lo stesso (soldi incassati) e lo staff riceve l'avviso
//         member_price_limit_late. L'insert va fatto in una transazione annidata (savepoint): su Postgres un
//         errore non gestito annulla tutta la transazione esterna.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('member_price_claims', function (Blueprint $table) use ($pgsql) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->string('kind', 20);  // full_pass, day_pass, paid_session
            $table->foreignUuid('event_day_id')->nullable()->constrained('event_days')->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('sessions')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 200)->nullable();

            $table->index('order_id');

            if (! $pgsql) {
                // MySQL non ha indici parziali né NULLS NOT DISTINCT: colonna generata con la chiave della
                // prenotazione solo se attiva (NULL se rilasciata) e indice unico su quella. I NULL di giorno e
                // sessione diventano '-' così due pass interi dello stesso membro collidono.
                $table->string('active_key', 200)->nullable()->storedAs(
                    "case when released_at is null then concat_ws('|', user_id, event_id, kind, "
                    ."coalesce(event_day_id, '-'), coalesce(session_id, '-')) end"
                );
                $table->unique('active_key', 'member_price_claims_one_active');
            }
        });

        // MySQL: da verificare se accetta un CHECK su colonne che hanno anche una FK (event_day_id, session_id);
        // se lo rifiuta, la forma della chiave resta nel Service e nei test.
        DB::statement("alter table member_price_claims add constraint member_price_claims_kind_shape check (
            (kind = 'full_pass'    and event_day_id is null     and session_id is null) or
            (kind = 'day_pass'     and event_day_id is not null and session_id is null) or
            (kind = 'paid_session' and event_day_id is null     and session_id is not null))");

        if ($pgsql) {
            DB::statement('create unique index member_price_claims_one_active
                on member_price_claims (user_id, event_id, kind, event_day_id, session_id) nulls not distinct
                where released_at is null');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_price_claims');
    }
};
