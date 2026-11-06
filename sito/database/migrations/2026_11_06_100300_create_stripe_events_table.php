<?php

// Migrazione della Fase 1, dalla bozza in db/migrazioni-bozza/. Autore: Giulio Occhipinti.
//
// Idempotenza dei webhook Stripe: l'id dell'evento (evt_...) è la chiave primaria.
// Scritta per MySQL 8.0.16+ / MariaDB 10.7+ (database principale dal 06/11/2026). Il ramo Postgres resta nella
// storia di git.
// Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e MariaDB 10.11.14 (db/modello-dati.md §6).
//
// Flusso su hosting condiviso, senza processi sempre attivi (dettagli in docs/architettura.md §6.6 e §8):
//   1. StripeWebhookController legge il corpo grezzo, verifica la firma con \Stripe\Webhook::constructEvent e il
//      segreto dell'endpoint, rifiuta un livemode diverso dall'ambiente.
//   2. Nella stessa transazione: insertOrIgnore in stripe_events (un reinvio di Stripe non inserisce nulla) e
//      dispatch del job ProcessStripeEvent sulla coda "database" (la riga del job entra nella stessa transazione:
//      nessun evento perso se il processo cade tra le due scritture). Risposta 200 in pochi millisecondi.
//   3. Il cron di ogni minuto avvia queue:work --stop-when-empty --max-time=50: il job blocca la riga dell'evento
//      (lockForUpdate), esce se è già processed o ignored, elabora con lo stesso Service usato dalla pagina di
//      successo e segna processed; in errore failed + last_error + nuovo tentativo con attesa crescente; dopo
//      l'ultimo tentativo avviso allo staff.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('type', 100);
            $table->boolean('livemode');
            $table->string('api_version', 40)->nullable();
            $table->dateTime('stripe_created_at');
            $table->dateTime('received_at')->useCurrent();
            $table->string('status', 20)->default('received');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            // es. cs_..., sub_..., in_..., ch_...: per trovare tutti gli eventi di un oggetto e scartare quelli più
            // vecchi dell'ultimo applicato (Stripe non garantisce l'ordine di consegna).
            $table->string('object_id', 255)->nullable();
            // Contiene dati personali: il job PruneStripePayloads lo azzera dopo 90 giorni e lascia i metadati.
            $table->json('payload')->nullable();

            $table->index(['object_id', 'stripe_created_at']);
            // Per la riconciliazione: eventi ricevuti ma non ancora elaborati, o falliti.
            $table->index(['status', 'received_at'], 'stripe_events_pending_idx');
        });

        DB::statement("alter table stripe_events add constraint stripe_events_id_prefix check (id like 'evt\\_%')");
        DB::statement("alter table stripe_events add constraint stripe_events_status_valid check (
            status in ('received', 'processing', 'processed', 'failed', 'ignored'))");
        DB::statement('alter table stripe_events add constraint stripe_events_attempts_positive check (attempts >= 0)');
        DB::statement("alter table stripe_events add constraint stripe_events_processed_dated check (
            status <> 'processed' or processed_at is not null)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
