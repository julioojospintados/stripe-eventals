<?php

// Migrazione della Fase 1, dalla bozza in db/migrazioni-bozza/. Autore: Giulio Occhipinti.
//
// Pool di posti e riserve temporanee: la parte che impedisce l'overselling.
// Scritta per MySQL 8.0.16+ / MariaDB 10.7+ (database principale dal 06/11/2026). Il ramo Postgres resta nella
// storia di git.
// Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e MariaDB 10.11.14 (db/modello-dati.md §6).
// Presuppone: events, ticket_types (090000), orders (100000).
//
// Regole:
//   - DB: held + confirmed <= capacity (CHECK, ultima difesa anche con un bug nel codice); contatori >= 0;
//     una riserva per (ordine, pool); riserva attiva sempre con scadenza; riserva rilasciata sempre con data.
//   - Service (App\Services\Capacity\CapacityService), sempre in DB::transaction($fn, attempts: 3):
//       * riserva: lockForUpdate sull'ordine, poi sui pool in ordine di id (niente deadlock), e
//         UPDATE capacity_pools SET held = held + n WHERE id = ? AND held + confirmed + n <= capacity;
//         0 righe aggiornate = esaurito. InnoDB, anche in REPEATABLE READ, fa l'UPDATE sull'ultima versione
//         confermata della riga dopo averne preso il lock: la seconda transazione aspetta e trova il posto preso.
//         Durata minima 30 minuti (minimo di Stripe) + 5 di margine;
//       * rilascio SOLO dopo conferma di Stripe (webhook checkout.session.expired o job che chiama expire):
//         niente rilascio "cieco" a tempo, che con un webhook in ritardo vendeva posti due volte;
//       * pagamento tardivo: riprenota se c'è posto (la riga released torna converted), altrimenti ordine
//         needs_refund e rimborso automatico;
//       * omaggi (orders.origin = complimentary): stesso UPDATE condizionale ma direttamente su confirmed, con una
//         riga converted per tracciarli. Contano nella capienza come i biglietti venduti.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            // Nome interno (es. "Giorno 1", "Workshop 3"): non tradotto, lo vede solo lo staff.
            $table->string('name', 120);
            $table->integer('capacity');
            $table->integer('held')->default(0);       // in riserva durante il checkout
            $table->integer('confirmed')->default(0);  // venduti, omaggi o prenotati
            // Limite di posti di questo pool in un solo ordine (es. 2 sui workshop), contro chi accaparra.
            $table->integer('max_per_order')->nullable();
            $table->datetimes();

            $table->unique(['event_id', 'name']);
        });

        DB::statement('alter table capacity_pools add constraint capacity_pools_counters_valid check (
            capacity >= 0 and held >= 0 and confirmed >= 0
            and (max_per_order is null or max_per_order > 0))');
        // Se lo staff prova a ridurre la capienza sotto i posti già venduti o in riserva, l'UPDATE fallisce: è voluto.
        // Filament mostra l'errore come messaggio leggibile (validazione nel form + eccezione intercettata).
        DB::statement('alter table capacity_pools add constraint capacity_pools_no_oversell check (held + confirmed <= capacity)');

        // Quali pool consuma ogni tipo di biglietto: 1 posto per pool per biglietto.
        // Pass intero, espositore, accompagnatore e omaggio consumano il pool di ogni giorno (proposta); un workshop
        // il suo pool.
        Schema::create('ticket_type_pools', function (Blueprint $table) {
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->cascadeOnDelete();
            $table->foreignUuid('pool_id')->constrained('capacity_pools')->restrictOnDelete();
            $table->primary(['ticket_type_id', 'pool_id']);
            $table->index('pool_id');
        });

        // Riserva temporanea dei posti, una riga per (ordine, pool).
        // expires_at = scadenza della Checkout Session + 5 minuti; null quando il pagamento è asincrono in corso
        // (awaiting_payment) o la riserva è già convertita o rilasciata.
        Schema::create('capacity_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('pool_id')->constrained('capacity_pools')->restrictOnDelete();
            $table->integer('quantity');
            $table->string('status', 20)->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 200)->nullable();
            $table->datetimes();

            $table->unique(['order_id', 'pool_id']);
            // Per il job ExpireStaleOrders (ogni minuto, dal cron): riserve attive scadute.
            $table->index(['status', 'expires_at'], 'capacity_holds_expiry_idx');
        });

        DB::statement("alter table capacity_holds add constraint capacity_holds_status_valid check (
            status in ('active', 'awaiting_payment', 'converted', 'released'))");
        DB::statement('alter table capacity_holds add constraint capacity_holds_quantity_positive check (quantity > 0)');
        DB::statement("alter table capacity_holds add constraint capacity_holds_active_expires check (
            status <> 'active' or expires_at is not null)");
        DB::statement("alter table capacity_holds add constraint capacity_holds_released_dated check (
            status <> 'released' or released_at is not null)");
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_holds');
        Schema::dropIfExists('ticket_type_pools');
        Schema::dropIfExists('capacity_pools');
    }
};
