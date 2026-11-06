<?php

// Migrazione della Fase 1, dalla bozza in db/migrazioni-bozza/. Autore: Giulio Occhipinti.
//
// Ordini e righe d'ordine, con le transizioni di stato ammesse.
// Scritta per MySQL 8.0.16+ / MariaDB 10.7+ (database principale dal 06/11/2026). Il ramo Postgres del
// 06/11/2026 mattina, provato su Postgres 16.15, resta nella storia di git.
// Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e MariaDB 10.11.14 (db/modello-dati.md §6).
// Presuppone: users (id bigint), events, discount_codes e la migrazione 090000 (ticket_types, price_tiers,
// ticket_prices).
//
// Dove stanno le regole (dettagli in db/modello-dati.md):
//   - DB: importi coerenti (CHECK), una sola riserva aperta per persona (colonna generata + indice unico),
//     transizioni di stato ammesse (trigger), ruolo, fascia, origine e importi "fotografati" non modificabili
//     (trigger), riga a prezzo membro di quantità 1 (CHECK), omaggi a costo zero e firmati da chi li emette (CHECK).
//   - Service (App\Services\Orders\OrderService): intestatario della riga membro = acquirente, ricontrollo della
//     membership fino alla scadenza della Checkout Session, fascia attiva letta alla riserva, ordine e tipo di una
//     riga non cambiano dopo l'insert. Sempre DB::transaction($fn, attempts: 3): con REPEATABLE READ un deadlock
//     si riprova.
//   - Enum PHP App\Enums\OrderStatus::canTransitionTo(): stessa tabella del trigger, per dare errori leggibili
//     prima di toccare il database.

use App\Database\CreatesTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use CreatesTriggers;

    /** Transizioni ammesse "da>a". Tenere allineata con App\Enums\OrderStatus. */
    private const TRANSITIONS = [
        'pending>paid', 'pending>processing', 'pending>expired', 'pending>cancelled', 'pending>failed',
        'pending>needs_refund',
        'processing>paid', 'processing>failed', 'processing>needs_refund',
        // Pagamento tardivo: i soldi sono stati presi dopo la chiusura dell'ordine.
        'expired>paid', 'failed>paid', 'cancelled>paid',
        'expired>needs_refund', 'failed>needs_refund', 'cancelled>needs_refund',
        'paid>partially_refunded', 'paid>refunded', 'partially_refunded>refunded',
        'needs_refund>refunded',
        // Omaggio revocato dallo staff (nessun rimborso: non c'è pagamento). Solo per gli ordini complimentary:
        // il trigger rifiuta paid>cancelled sugli ordini pagati con Stripe.
        'paid>cancelled',
    ];

    private const STATUSES = [
        'pending', 'processing', 'paid', 'expired', 'failed', 'needs_refund', 'cancelled',
        'partially_refunded', 'refunded',
    ];

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Email normalizzata in minuscolo dal model (mutator).
            $table->string('buyer_email', 254);
            $table->string('status', 20)->default('pending');
            // checkout: acquisto sul sito con Stripe (o a costo zero con un codice al 100%);
            // complimentary: omaggio emesso dal pannello per speaker, staff o sponsor.
            $table->string('origin', 20)->default('checkout');
            // Ruolo di prezzo dell'acquirente "fotografato" alla riserva (valido fino alla scadenza della sessione).
            $table->string('price_role', 10);
            // Fascia attiva alla riserva (null se nessuna fascia era attiva o per gli omaggi). Non cambia più.
            $table->foreignUuid('price_tier_id')->nullable()->constrained('price_tiers')->restrictOnDelete();
            $table->char('currency', 3)->default('eur');
            $table->integer('subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->integer('total_cents');
            // Imposte: null finché il cliente non decide con il suo commercialista.
            $table->integer('tax_cents')->nullable();
            // RESTRICT e non SET NULL: i codici si disattivano, non si cancellano; e MySQL vieta i CHECK su colonne
            // con azioni referenziali (il CHECK degli omaggi usa questa colonna).
            $table->foreignUuid('discount_code_id')->nullable()->constrained('discount_codes')->restrictOnDelete();
            // Omaggi: chi li ha emessi e perché (speaker, staff, sponsor, other). Scritti anche nel registro audit.
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('comp_reason', 20)->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->dateTime('checkout_expires_at')->nullable();
            $table->boolean('discount_reserved')->default(false);
            $table->dateTime('paid_at')->nullable();
            $table->boolean('livemode');
            $table->integer('stripe_amount_total_cents')->nullable();
            // Lingua dell'acquirente: email e pagina Stripe nella stessa lingua (en predefinito, it).
            $table->string('locale', 5)->default('en');
            $table->datetimes();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'checkout_expires_at'], 'orders_open_idx');
            $table->index(['event_id', 'origin', 'status']);

            // MySQL non ha indici parziali: colonna generata che vale user_id solo per gli ordini pending,
            // NULL altrimenti. Un indice unico ammette più NULL, quindi blocca solo il secondo pending.
            $table->unsignedBigInteger('pending_user_id')->nullable()
                ->storedAs("case when status = 'pending' then user_id end");
            $table->unique('pending_user_id', 'orders_one_pending_per_user');
        });

        $statuses = "'".implode("','", self::STATUSES)."'";
        DB::statement("alter table orders add constraint orders_status_valid check (status in ($statuses))");
        DB::statement("alter table orders add constraint orders_origin_valid check (origin in ('checkout', 'complimentary'))");
        DB::statement("alter table orders add constraint orders_price_role_valid check (price_role in ('public', 'member'))");
        DB::statement('alter table orders add constraint orders_amounts_valid check (
            subtotal_cents >= 0 and discount_cents >= 0 and total_cents >= 0
            and total_cents = subtotal_cents - discount_cents
            and (tax_cents is null or tax_cents >= 0))');
        DB::statement("alter table orders add constraint orders_paid_has_date check (status <> 'paid' or paid_at is not null)");
        DB::statement('alter table orders add constraint orders_currency_lower check (currency = lower(currency))');
        // Omaggio: costo zero, nessun codice sconto, nessuna sessione Stripe, firmato da chi lo emette, con il motivo;
        // nasce già pagato (insert diretto in paid), quindi non occupa mai la "riserva aperta" del beneficiario.
        DB::statement("alter table orders add constraint orders_comp_shape check (
            (origin = 'complimentary'
               and total_cents = 0 and subtotal_cents = 0 and discount_code_id is null
               and stripe_checkout_session_id is null and issued_by is not null
               and comp_reason is not null and comp_reason in ('speaker', 'staff', 'sponsor', 'other')
               and status in ('paid', 'cancelled'))
            or (origin = 'checkout' and issued_by is null and comp_reason is null))");

        // Un trigger solo per tabella ed evento: MariaDB e MySQL ne ammettono più d'uno, ma uno solo è più facile da
        // leggere e da togliere. Serve il privilegio TRIGGER; con il log binario attivo MySQL può chiedere anche
        // log_bin_trust_function_creators: da verificare sull'hosting (docs/architettura.md §14).
        $allowed = "'".implode("','", self::TRANSITIONS)."'";
        $this->trigger(<<<SQL
            create trigger orders_guard before update on orders
            for each row
            begin
              if new.status <> old.status and concat(old.status, '>', new.status) not in ($allowed) then
                signal sqlstate '45000' set message_text = 'order_invalid_transition';
              end if;
              if old.status = 'paid' and new.status = 'cancelled' and old.origin <> 'complimentary' then
                signal sqlstate '45000' set message_text = 'order_invalid_transition';
              end if;
              if not (new.origin <=> old.origin) or not (new.price_role <=> old.price_role)
                 or not (new.price_tier_id <=> old.price_tier_id) or not (new.user_id <=> old.user_id)
                 or not (new.event_id <=> old.event_id) or not (new.subtotal_cents <=> old.subtotal_cents)
                 or not (new.discount_cents <=> old.discount_cents) or not (new.total_cents <=> old.total_cents) then
                signal sqlstate '45000' set message_text = 'order_snapshot_immutable';
              end if;
            end
            SQL);

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->integer('quantity');
            // Prezzo membro solo sul biglietto del membro che compra: righe member di quantità 1, intestate
            // all'acquirente (controllo in OrderService), al massimo una per membro, evento e tipo
            // (member_price_claims, migrazione 100200).
            $table->string('price_role', 10);
            $table->integer('list_unit_amount_cents');
            $table->integer('unit_discount_cents')->default(0);
            // Importo per unità inviato a Stripe in price_data.unit_amount: sconto già incluso.
            $table->integer('unit_amount_cents');
            $table->integer('total_cents');
            // Testo della riga sulla pagina Stripe e in ricevuta, nella lingua dell'ordine.
            $table->string('stripe_line_name', 250);
            // Prezzo usato (tipo × fascia × ruolo); null per gli omaggi. RESTRICT: un prezzo usato non si cancella.
            $table->foreignUuid('ticket_price_id')->nullable()->constrained('ticket_prices')->restrictOnDelete();
            // Nominativi raccolti nel carrello: [{"name": "...", "email": "..."}], uno per unità. Diventano i
            // nominativi dei biglietti e non cambiano più (nessun cambio di nome dal 06/11/2026).
            $table->json('attendees');
            $table->datetimes();

            $table->index('order_id');
        });

        DB::statement("alter table order_items add constraint order_items_price_role_valid check (price_role in ('public', 'member'))");
        DB::statement('alter table order_items add constraint order_items_amounts_valid check (
            quantity between 1 and 50
            and list_unit_amount_cents >= 0 and unit_discount_cents >= 0
            and unit_discount_cents <= list_unit_amount_cents
            and unit_amount_cents = list_unit_amount_cents - unit_discount_cents
            and total_cents = unit_amount_cents * quantity)');
        DB::statement("alter table order_items add constraint order_items_member_qty_one check (price_role <> 'member' or quantity = 1)");
        DB::statement("alter table order_items add constraint order_items_attendees_match check (
            json_type(attendees) = 'ARRAY' and json_length(attendees) = quantity)");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        DB::unprepared('drop trigger if exists orders_guard');
        Schema::dropIfExists('orders');
    }
};
