<?php

// Bozza di migrazione Laravel. Autore: Giulio Occhipinti. Aggiornata al 06/11/2026.
//
// Idempotenza dei webhook Stripe: l'id dell'evento (evt_...) è la chiave primaria.
// Scritta per Postgres 16+. Ramo MySQL indicato ma non provato.
//
// Flusso (dettagli in docs/architettura.md, sezione webhook):
//   1. StripeWebhookController legge il corpo grezzo, verifica la firma con \Stripe\Webhook::constructEvent e il
//      segreto dell'endpoint, rifiuta un livemode diverso dall'ambiente.
//   2. Nella stessa transazione: insertOrIgnore in stripe_events (un reinvio di Stripe non inserisce nulla) e
//      dispatch del job ProcessStripeEvent sulla coda "database" (la riga del job entra nella stessa transazione:
//      nessun evento perso se il processo cade tra le due scritture). Risposta 200 in pochi millisecondi.
//   3. Il job blocca la riga dell'evento (lockForUpdate), esce se è già processed o ignored, elabora con lo
//      stesso Service usato dalla pagina di successo e segna processed; in errore failed + last_error + nuovo
//      tentativo con attesa crescente; dopo l'ultimo tentativo avviso allo staff.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('stripe_events', function (Blueprint $table) use ($pgsql) {
            $table->string('id', 255)->primary();
            $table->string('type', 100);
            $table->boolean('livemode');
            $table->string('api_version', 40)->nullable();
            $table->timestampTz('stripe_created_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->string('status', 20)->default('received');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            // es. cs_..., sub_..., in_..., ch_...: per trovare tutti gli eventi di un oggetto e scartare quelli più
            // vecchi dell'ultimo applicato (Stripe non garantisce l'ordine di consegna).
            $table->string('object_id', 255)->nullable();
            // Contiene dati personali: il job PruneStripePayloads lo azzera dopo 90 giorni e lascia i metadati.
            $table->jsonb('payload')->nullable();

            $table->index(['object_id', 'stripe_created_at']);
            if (! $pgsql) {
                $table->index(['status', 'received_at'], 'stripe_events_pending_idx');
            }
        });

        DB::statement("alter table stripe_events add constraint stripe_events_id_prefix check (id like 'evt\\_%')");
        DB::statement("alter table stripe_events add constraint stripe_events_status_valid check (
            status in ('received', 'processing', 'processed', 'failed', 'ignored'))");
        DB::statement('alter table stripe_events add constraint stripe_events_attempts_positive check (attempts >= 0)');
        DB::statement("alter table stripe_events add constraint stripe_events_processed_dated check (
            status <> 'processed' or processed_at is not null)");

        if ($pgsql) {
            // Per la riconciliazione: eventi ricevuti ma non ancora elaborati, o falliti.
            DB::statement("create index stripe_events_pending_idx on stripe_events (received_at)
                where status in ('received', 'processing', 'failed')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
