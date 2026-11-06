<?php

// Passo 2: codici di accesso dei clienti, email già spedite (dedupe) e rimborsi. Autore: Giulio Occhipinti.
// Regole (docs/architettura.md §5.1, §6.7, §9):
//   - login_codes: il codice non si salva mai in chiaro (HMAC con APP_KEY), scade in 10 minuti, 5 tentativi.
//   - sent_messages: un'email con la stessa dedupe_key parte una volta sola anche se il job si ripete.
//   - refunds: un rimborso per id Stripe e per Idempotency-Key; quelli nati da noi hanno sempre la causa.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->index();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('sent_messages', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 200)->unique();
            $table->string('mailable', 100);
            // Hash dell'indirizzo: basta per i controlli, non è un dato personale in chiaro.
            $table->char('to_hash', 64);
            $table->dateTime('sent_at')->useCurrent();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->integer('amount_cents');
            $table->char('currency', 3)->default('eur');
            // no_capacity (pagato senza posti), event_cancelled, other; null solo se nato nella Dashboard Stripe.
            $table->string('cause', 30)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');   // pending, succeeded, failed, canceled
            $table->string('source', 20);                        // auto, staff, dashboard
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->boolean('needs_review')->default(false);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();
        });

        DB::statement("alter table refunds add constraint refunds_source_valid check (source in ('auto', 'staff', 'dashboard'))");
        DB::statement("alter table refunds add constraint refunds_cause_required check (source = 'dashboard' or cause is not null)");
        DB::statement('alter table refunds add constraint refunds_amount_positive check (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sent_messages');
        Schema::dropIfExists('login_codes');
    }
};
