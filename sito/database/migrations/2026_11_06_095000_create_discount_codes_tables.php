<?php

// Codici sconto (db/modello-dati.md §3.3). Autore: Giulio Occhipinti.
// DB: forma percentuale/importo, utilizzi mai oltre il massimo, formato del codice.
// Service (passo 2): calcolo riga per riga dal server, utilizzo contato alla riserva e restituito alla scadenza.
// Policy: li creano commerciale, organizzatore e admin; created_by e registro audit dicono chi.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Salvato in maiuscolo dal model.
            $table->string('code', 40)->unique();
            $table->string('description', 500)->nullable();
            // Null = vale anche fuori da un evento (membership).
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->string('discount_type', 10);  // percent, amount
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->char('currency', 3)->default('eur');
            // Ruoli di prezzo ammessi, es. ["public"]; null = tutti.
            $table->json('eligible_roles')->nullable();
            $table->boolean('combinable_with_role_price')->default(false);
            $table->boolean('applies_to_membership')->default(false);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();
        });

        DB::statement("alter table discount_codes add constraint discount_codes_code_format check (code regexp '^[A-Z0-9_-]{3,40}$')");
        DB::statement("alter table discount_codes add constraint discount_codes_shape check (
            (discount_type = 'percent' and percent_off is not null and percent_off between 1 and 100
                and amount_off_cents is null)
            or (discount_type = 'amount' and amount_off_cents is not null and amount_off_cents > 0
                and percent_off is null))");
        DB::statement('alter table discount_codes add constraint discount_codes_redemptions_valid check (
            max_redemptions is null or redeemed_count <= max_redemptions)');
        DB::statement('alter table discount_codes add constraint discount_codes_window check (
            valid_from is null or valid_to is null or valid_to > valid_from)');
        DB::statement('alter table discount_codes add constraint discount_codes_currency_lower check (currency = lower(currency))');

        // Nessuna riga = il codice vale per tutti i tipi di biglietto dell'evento.
        Schema::create('discount_code_ticket_types', function (Blueprint $table) {
            $table->foreignUuid('discount_code_id')->constrained('discount_codes')->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->primary(['discount_code_id', 'ticket_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_code_ticket_types');
        Schema::dropIfExists('discount_codes');
    }
};
