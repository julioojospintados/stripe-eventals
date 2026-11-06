<?php

// Migrazione della Fase 1, dalla bozza in db/migrazioni-bozza/. Autore: Giulio Occhipinti.
//
// Tipi di biglietto, fasce di prezzo (early bird, regular, last minute) e prezzi per (tipo × fascia × ruolo).
// Scritta per MySQL 8.0.16+ / MariaDB 10.7+ (database principale dal 06/11/2026: hosting condiviso del cliente).
// Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e MariaDB 10.11.14 (db/modello-dati.md §6).
// Presuppone migrazioni precedenti per: events, event_days, program_sessions (le sessioni del programma:
// «sessions» è la tabella delle sessioni HTTP di Laravel).
//
// Regole:
//   - DB: forma del tipo di biglietto (CHECK); fasce dello stesso evento mai sovrapposte (trigger: a ogni istante
//     al massimo una fascia attiva, quindi una sola per ogni tipo di biglietto); un solo prezzo per (tipo, fascia,
//     ruolo), con "fascia vuota" = prezzo base (colonna generata + indice unico); importi >= 0.
//   - Service (App\Services\Pricing\PriceResolver): prezzo per (tipo, ruolo, istante della riserva):
//       1. fascia attiva adesso (starts_at <= adesso < ends_at), se c'è;
//       2. prezzo (tipo, fascia, ruolo), altrimenti prezzo base (tipo, nessuna fascia, ruolo);
//       3. per il ruolo member senza prezzo: lo stesso calcolo con il ruolo public (il membro paga il pubblico);
//       4. nessun prezzo = tipo non acquistabile in quel momento.
//     Esempio EAS8: base public 695 €, base member 395 €, early bird member 276,50 €; nessun early bird public,
//     quindi in early bird il pubblico paga 695 € (early bird solo per i membri). Workshop: base public 100 €.
//   - La fascia si fissa sull'ordine alla riserva (orders.price_tier_id, migrazione 100000): se la fascia finisce
//     durante il pagamento il prezzo non cambia.
//   - Service + validazione del form Filament: lock sull'evento (lockForUpdate) prima di salvare le fasce, per
//     dare un errore leggibile; il trigger resta l'ultima difesa.

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
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            // full_pass, day_pass, paid_session (workshop), companion (accompagnatore), exhibitor (espositore e
            // sponsor), staff_pass (omaggio per speaker e staff, solo dal pannello).
            $table->string('kind', 20);
            // public: in vendita sul sito; invite: in vendita solo con un codice d'invito (espositori);
            // panel: emesso solo dal pannello (omaggi), mai acquistabile.
            $table->string('channel', 10)->default('public');
            $table->json('name');          // tradotto: {"en": "...", "it": "..."}
            $table->json('description')->nullable();
            // FK con RESTRICT (non SET NULL o CASCADE): MySQL vieta i CHECK su colonne con azioni referenziali.
            $table->foreignUuid('event_day_id')->nullable()->constrained('event_days')->restrictOnDelete();
            $table->foreignUuid('program_session_id')->nullable()->constrained('program_sessions')->restrictOnDelete();
            $table->unsignedSmallInteger('max_per_order')->default(4);
            // Solo per companion: quanti accompagnatori per pass (proposta: 1).
            $table->unsignedTinyInteger('max_per_host')->nullable();
            // Finestra di vendita del tipo (facoltativa, dentro quella dell'evento).
            $table->dateTime('sales_start')->nullable();
            $table->dateTime('sales_end')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->index(['event_id', 'active', 'position']);
        });

        DB::statement("alter table ticket_types add constraint ticket_types_kind_valid check (
            kind in ('full_pass', 'day_pass', 'paid_session', 'companion', 'exhibitor', 'staff_pass'))");
        DB::statement("alter table ticket_types add constraint ticket_types_channel_valid check (
            channel in ('public', 'invite', 'panel'))");
        DB::statement("alter table ticket_types add constraint ticket_types_kind_shape check (
            (kind = 'day_pass'     and event_day_id is not null and program_session_id is null) or
            (kind = 'paid_session' and event_day_id is null     and program_session_id is not null) or
            (kind in ('full_pass', 'companion', 'exhibitor', 'staff_pass')
                                   and event_day_id is null     and program_session_id is null))");
        // Omaggi solo dal pannello; accompagnatori con un limite per pass. Attenzione: un CHECK che vale NULL passa,
        // quindi ogni confronto su una colonna nullable porta il suo "is not null" (provato: senza, un companion
        // senza limite veniva accettato).
        DB::statement("alter table ticket_types add constraint ticket_types_channel_shape check (
            (kind <> 'staff_pass' or channel = 'panel')
            and (kind <> 'companion' or (max_per_host is not null and max_per_host between 1 and 5))
            and (kind = 'companion' or max_per_host is null))");
        DB::statement('alter table ticket_types add constraint ticket_types_sales_window check (
            sales_start is null or sales_end is null or sales_end > sales_start)');

        // Fasce di prezzo dell'evento, con date modificabili dal pannello.
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->string('code', 30);    // early_bird, regular, last_minute
            $table->json('name');          // tradotto: {"en": "Early bird", "it": "Early bird"}
            $table->dateTime('starts_at'); // UTC, inclusa
            $table->dateTime('ends_at');   // UTC, esclusa
            // Fascia spenta (es. last minute senza prezzo): non vale mai e non occupa le sue date.
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->datetimes();

            $table->unique(['event_id', 'code']);
            $table->index(['event_id', 'starts_at']);
        });

        DB::statement("alter table price_tiers add constraint price_tiers_code_format check (code regexp '^[a-z_]{2,30}$')");
        DB::statement('alter table price_tiers add constraint price_tiers_window check (ends_at > starts_at)');

        // Fasce attive mai sovrapposte nello stesso evento. Un trigger può leggere la tabella che lo attiva (non
        // modificarla). Due salvataggi in parallelo non sono coperti dal trigger: li serializza il Service con il
        // lock sull'evento.
        foreach (['insert', 'update'] as $op) {
            $this->trigger(<<<SQL
                create trigger price_tiers_no_overlap_{$op} before {$op} on price_tiers
                for each row
                begin
                  if exists (select 1 from price_tiers t
                             where t.event_id = new.event_id and t.id <> new.id and t.active and new.active
                               and t.starts_at < new.ends_at and new.starts_at < t.ends_at) then
                    signal sqlstate '45000' set message_text = 'price_tier_overlap';
                  end if;
                end
                SQL);
        }

        Schema::create('ticket_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            // NULL = prezzo base, valido in ogni fascia che non ha un prezzo suo.
            $table->foreignUuid('price_tier_id')->nullable()->constrained('price_tiers')->restrictOnDelete();
            $table->string('price_role', 10);  // public, member
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('eur');
            $table->datetimes();

            // MySQL non ha NULLS NOT DISTINCT: '*' al posto della fascia vuota, così due prezzi base dello stesso
            // tipo e ruolo collidono sull'indice unico. Il cast serve a MariaDB, dove Laravel crea le colonne uuid
            // con il tipo nativo UUID e coalesce(uuid, '*') fallisce (provato il 06/11/2026 su MariaDB 10.11).
            $table->string('tier_key', 36)->storedAs("coalesce(cast(price_tier_id as char(36)), '*')");
            $table->unique(['ticket_type_id', 'tier_key', 'price_role'], 'ticket_prices_one_per_tier_role');
        });

        DB::statement("alter table ticket_prices add constraint ticket_prices_role_valid check (price_role in ('public', 'member'))");
        DB::statement('alter table ticket_prices add constraint ticket_prices_currency_lower check (currency = lower(currency))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_prices');
        DB::unprepared('drop trigger if exists price_tiers_no_overlap_insert');
        DB::unprepared('drop trigger if exists price_tiers_no_overlap_update');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('ticket_types');
    }
};
