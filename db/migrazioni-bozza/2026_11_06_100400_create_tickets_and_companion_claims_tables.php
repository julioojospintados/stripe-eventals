<?php

// Bozza di migrazione Laravel. Autore: Giulio Occhipinti. Aggiornata al 06/11/2026.
//
// Biglietti nominativi e legame accompagnatore → pass.
// Scritta per MySQL 8.0.16+ / MariaDB 10.7+. Provata il 06/11/2026 con Laravel 13.40 su MySQL 8.0.46 e
// MariaDB 10.11.14 (db/modello-dati.md §6).
// Presuppone: users, events, ticket_types (090000), orders e order_items (100000).
//
// Regole:
//   - DB:
//       * un biglietto per (riga, numero): una conferma ripetuta (webhook doppio, pagina di successo) non emette
//         biglietti doppi;
//       * nominativo immutabile (nessun cambio di nome, decisione del cliente del 06/11/2026). Unica eccezione:
//         correzione di un refuso da parte di un admin, che il trigger ammette solo se nella stessa UPDATE
//         aumenta di 1 il contatore delle correzioni (massimo 3) e firma chi corregge e quando;
//       * ordine, riga, numero, tipo, ruolo di prezzo e pass ospite non cambiano mai; un biglietto annullato o
//         rimborsato non torna valido;
//       * al massimo max_per_host accompagnatori attivi per pass (companion_claims: colonna generata + indice
//         unico su pass e posto).
//   - Service (App\Services\Tickets\TicketService::correctAttendee): solo Policy admin con MFA, motivo
//     obbligatorio, riga in audit_log nella stessa transazione; se cambia l'email qr_version + 1 e biglietto
//     rimandato al nuovo indirizzo; se cambia solo il nome il QR resta valido (non contiene il nome).
//   - Service (App\Services\Orders\CompanionService): l'accompagnatore si compra solo con un pass valido
//     (full_pass o exhibitor) intestato all'acquirente, in un ordine separato dopo il pass; posto libero più basso
//     tra 1 e max_per_host del tipo companion; prenotazione liberata come quella del prezzo membro.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Migrazione scritta per MySQL 8.0.16+ / MariaDB 10.7+.');
        }

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->foreignUuid('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->string('price_role', 10);
            $table->string('attendee_name', 200);
            $table->string('attendee_email', 254);
            $table->foreignId('attendee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            // Solo per gli accompagnatori: il pass a cui sono legati.
            $table->foreignUuid('host_ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->string('status', 20)->default('valid');
            $table->unsignedInteger('qr_version')->default(1);
            $table->string('qr_key_id', 40);
            $table->char('short_code', 8)->unique();
            $table->dateTime('issued_at');
            $table->dateTime('cancelled_at')->nullable();
            // Correzioni di refusi fatte da un admin (con audit), non cambi di nome.
            $table->unsignedTinyInteger('attendee_corrections_count')->default(0);
            $table->dateTime('attendee_corrected_at')->nullable();
            $table->foreignId('attendee_corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();

            $table->unique(['order_item_id', 'seq'], 'tickets_one_per_item_seq');
            $table->index(['event_id', 'status', 'updated_at']);  // export ed API del partner (updated_since)
            $table->index('attendee_email');
        });

        DB::statement("alter table tickets add constraint tickets_status_valid check (
            status in ('valid', 'cancelled', 'refunded', 'disputed'))");
        DB::statement("alter table tickets add constraint tickets_price_role_valid check (price_role in ('public', 'member'))");
        DB::statement('alter table tickets add constraint tickets_numbers_valid check (
            seq >= 1 and qr_version >= 1 and attendee_corrections_count <= 3)');
        // 8 caratteri senza quelli ambigui (0, O, 1, I, L).
        DB::statement("alter table tickets add constraint tickets_short_code_format check (
            short_code regexp '^[A-HJKMNP-Z2-9]{8}$')");
        DB::statement("alter table tickets add constraint tickets_cancelled_dated check (
            status not in ('cancelled', 'refunded') or cancelled_at is not null)");

        $this->trigger(<<<'SQL'
            create trigger tickets_guard before update on tickets
            for each row
            begin
              if not (new.event_id <=> old.event_id) or not (new.order_id <=> old.order_id)
                 or not (new.order_item_id <=> old.order_item_id) or not (new.seq <=> old.seq)
                 or not (new.ticket_type_id <=> old.ticket_type_id) or not (new.price_role <=> old.price_role)
                 or not (new.host_ticket_id <=> old.host_ticket_id) then
                signal sqlstate '45000' set message_text = 'ticket_immutable';
              end if;
              if (not (new.attendee_name <=> old.attendee_name) or not (new.attendee_email <=> old.attendee_email))
                 and not (new.attendee_corrections_count = old.attendee_corrections_count + 1
                          and new.attendee_corrected_by is not null
                          and new.attendee_corrected_at is not null
                          and not (new.attendee_corrected_at <=> old.attendee_corrected_at)) then
                signal sqlstate '45000' set message_text = 'ticket_attendee_immutable';
              end if;
              if old.status in ('cancelled', 'refunded') and new.status <> old.status then
                signal sqlstate '45000' set message_text = 'ticket_invalid_transition';
              end if;
            end
            SQL);

        // I biglietti non si cancellano mai: si annullano.
        $this->trigger(<<<'SQL'
            create trigger tickets_no_delete before delete on tickets
            for each row
            begin
              signal sqlstate '45000' set message_text = 'ticket_delete_forbidden';
            end
            SQL);

        Schema::create('companion_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('host_ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->unsignedTinyInteger('slot');  // 1 .. max_per_host del tipo companion
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 200)->nullable();

            $table->string('active_key', 50)->nullable()->storedAs(
                "case when released_at is null then concat(host_ticket_id, '|', slot) end"
            );
            $table->unique('active_key', 'companion_claims_one_per_slot');
        });

        DB::statement('alter table companion_claims add constraint companion_claims_slot_valid check (slot between 1 and 5)');
    }

    /**
     * Crea un trigger di difesa. Su MySQL con il log binario attivo un utente senza SUPER riceve l'errore 1419
     * (provato il 06/11/2026 su MySQL 8.0.46): l'hosting deve impostare log_bin_trust_function_creators = 1. Se non
     * può, DB_TRIGGERS_OPTIONAL=true fa saltare il trigger con un avviso nel log: la regola resta nel Service e nei
     * test, e il controllo di salute dell'app (da scrivere) segnala i trigger mancanti (docs/architettura.md §14).
     */
    private function trigger(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $e) {
            $optional = filter_var(env('DB_TRIGGERS_OPTIONAL', false), FILTER_VALIDATE_BOOL);
            if (($e->errorInfo[1] ?? null) === 1419 && $optional) {
                Log::warning('Trigger di difesa non creato (errore 1419): '.strtok(trim($sql), "\n"));

                return;
            }
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_claims');
        DB::unprepared('drop trigger if exists tickets_no_delete');
        DB::unprepared('drop trigger if exists tickets_guard');
        Schema::dropIfExists('tickets');
    }
};
