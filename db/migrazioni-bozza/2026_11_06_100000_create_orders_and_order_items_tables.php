<?php

// Bozza di migrazione Laravel. Autore: Giulio Occhipinti. Aggiornata al 06/11/2026.
//
// Ordini e righe d'ordine, con le transizioni di stato ammesse.
// Scritta per Postgres 16+ (consigliato). I rami MySQL 8.0.16+ / MariaDB sono indicati ma non provati.
// Presuppone migrazioni precedenti per: users (id bigint), events, ticket_types, ticket_prices, discount_codes.
//
// Dove stanno le regole (dettagli in db/modello-dati.md):
//   - DB: importi coerenti (CHECK), una sola riserva aperta per persona (indice unico parziale),
//     transizioni di stato ammesse (trigger), riga a prezzo membro di quantità 1 (CHECK).
//   - Service (App\Services\Orders\OrderService): intestatario della riga membro = acquirente, ricontrollo della
//     membership fino alla scadenza della Checkout Session, ordine e tipo di una riga non cambiano dopo l'insert.
//   - Enum PHP App\Enums\OrderStatus::canTransitionTo(): stessa tabella del trigger, per dare errori leggibili
//     prima di toccare il database.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
    ];

    private const STATUSES = [
        'pending', 'processing', 'paid', 'expired', 'failed', 'needs_refund', 'cancelled',
        'partially_refunded', 'refunded',
    ];

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('orders', function (Blueprint $table) use ($pgsql) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Email normalizzata in minuscolo dal model (mutator): niente citext, uguale su Postgres e MySQL.
            $table->string('buyer_email', 254);
            $table->string('status', 20)->default('pending');
            // Ruolo di prezzo dell'acquirente "fotografato" alla creazione della Checkout Session.
            $table->string('price_role', 10);
            $table->char('currency', 3)->default('eur');
            $table->integer('subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->integer('total_cents');
            // Imposte: null finché la catena legale-fiscale non decide (domanda aperta).
            $table->integer('tax_cents')->nullable();
            $table->foreignUuid('discount_code_id')->nullable()->constrained('discount_codes')->nullOnDelete();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestampTz('checkout_expires_at')->nullable();
            $table->boolean('discount_reserved')->default(false);
            $table->timestampTz('paid_at')->nullable();
            $table->boolean('livemode');
            $table->integer('stripe_amount_total_cents')->nullable();
            // Lingua dell'acquirente: email e pagina Stripe nella stessa lingua (it, en).
            $table->string('locale', 5)->default('en');
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);

            if (! $pgsql) {
                // MySQL non ha indici parziali: colonna generata che vale user_id solo per gli ordini pending,
                // NULL altrimenti. Un indice unico ammette più NULL, quindi blocca solo il secondo pending.
                $table->unsignedBigInteger('pending_user_id')->nullable()
                    ->storedAs("case when status = 'pending' then user_id end");
                $table->unique('pending_user_id', 'orders_one_pending_per_user');
                $table->index(['status', 'checkout_expires_at'], 'orders_open_idx');
            }
        });

        $statuses = "'".implode("','", self::STATUSES)."'";
        DB::statement("alter table orders add constraint orders_status_valid check (status in ($statuses))");
        DB::statement("alter table orders add constraint orders_price_role_valid check (price_role in ('public', 'member'))");
        DB::statement('alter table orders add constraint orders_amounts_valid check (
            subtotal_cents >= 0 and discount_cents >= 0 and total_cents >= 0
            and total_cents = subtotal_cents - discount_cents
            and (tax_cents is null or tax_cents >= 0))');
        DB::statement("alter table orders add constraint orders_paid_has_date check (status <> 'paid' or paid_at is not null)");
        DB::statement("alter table orders add constraint orders_currency_lower check (currency = lower(currency))");

        if ($pgsql) {
            // Una sola riserva aperta per persona: prima di un nuovo checkout il server chiama expire sulla
            // Checkout Session precedente e chiude l'ordine.
            DB::statement("create unique index orders_one_pending_per_user on orders (user_id) where status = 'pending'");
            DB::statement("create index orders_open_idx on orders (status, checkout_expires_at) where status in ('pending', 'processing')");

            $allowed = "'".implode("','", self::TRANSITIONS)."'";
            DB::unprepared(<<<SQL
                create function orders_status_transition() returns trigger
                language plpgsql
                as \$\$
                begin
                  if new.status is distinct from old.status
                     and not (old.status || '>' || new.status) = any (array[$allowed]) then
                    raise exception 'order_invalid_transition' using errcode = 'P0001',
                      detail = old.status || '>' || new.status;
                  end if;
                  return new;
                end;
                \$\$;
                create trigger orders_status_transition before update of status on orders
                  for each row execute function orders_status_transition();
                SQL);
        } else {
            // MySQL 8 / MariaDB: stesso controllo con SIGNAL. Non provato in questa sessione.
            $allowed = "'".implode("','", self::TRANSITIONS)."'";
            DB::unprepared(<<<SQL
                create trigger orders_status_transition before update on orders
                for each row
                begin
                  if new.status <> old.status and concat(old.status, '>', new.status) not in ($allowed) then
                    signal sqlstate '45000' set message_text = 'order_invalid_transition';
                  end if;
                end
                SQL);
        }

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->integer('quantity');
            // Prezzo membro solo sul biglietto del membro che compra: righe member di quantità 1, intestate
            // all'acquirente (controllo in OrderService), al massimo una per membro, evento e tipo
            // (member_price_claims).
            $table->string('price_role', 10);
            $table->integer('list_unit_amount_cents');
            $table->integer('unit_discount_cents')->default(0);
            // Importo per unità inviato a Stripe in price_data.unit_amount: sconto già incluso.
            $table->integer('unit_amount_cents');
            $table->integer('total_cents');
            // Testo della riga sulla pagina Stripe e in ricevuta, nella lingua dell'ordine.
            $table->string('stripe_line_name', 250);
            $table->foreignUuid('ticket_price_id')->nullable()->constrained('ticket_prices')->nullOnDelete();
            // Nominativi raccolti nel carrello: [{"name": "...", "email": "..."}], uno per unità.
            $table->jsonb('attendees');
            $table->timestampsTz();

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

        if ($pgsql) {
            DB::statement("alter table order_items add constraint order_items_attendees_match check (
                jsonb_typeof(attendees) = 'array' and jsonb_array_length(attendees) = quantity)");
        } else {
            DB::statement("alter table order_items add constraint order_items_attendees_match check (
                json_type(attendees) = 'ARRAY' and json_length(attendees) = quantity)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('drop trigger if exists orders_status_transition on orders; drop function if exists orders_status_transition();');
        }
        Schema::dropIfExists('orders');
    }
};
