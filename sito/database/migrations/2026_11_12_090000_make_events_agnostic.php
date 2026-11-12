<?php

// Sistema agnostico (docs/tecnico/adr/0011-sistema-agnostico.md). Autore: Giulio Occhipinti.
//   - Niente valori di un evento in particolare nello schema: fuso e valuta senza default (prima Europe/Lisbon ed
//     eur). Li mettono i model: l'evento da config/eventals.php, prezzi, ordini e codici sconto dal loro evento.
//   - Due eventi non si mescolano: prezzo, pool di capienza e codice sconto legati a un tipo di biglietto stanno nello
//     stesso evento del tipo; prezzi e ordini sono nella valuta dell'evento; la valuta non cambia dopo il primo
//     prezzo o ordine. Trigger di difesa come in ADR 0002: le stesse regole stanno nei model e nel pannello.

use App\Database\CreatesTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use CreatesTriggers;

    private const NO_DEFAULT = [
        'events' => ['timezone', 'currency'], 'orders' => ['currency'], 'ticket_prices' => ['currency'],
        'discount_codes' => ['currency'], 'membership_plans' => ['currency'], 'refunds' => ['currency'],
    ];

    public function up(): void
    {
        foreach (self::NO_DEFAULT as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table $table alter column $column drop default");
            }
        }

        foreach (['insert', 'update'] as $op) {
            $this->trigger(<<<SQL
                create trigger ticket_prices_same_event_{$op} before {$op} on ticket_prices
                for each row
                begin
                  if not exists (select 1 from ticket_types t join events e on e.id = t.event_id
                                 where t.id = new.ticket_type_id and e.currency = new.currency) then
                    signal sqlstate '45000' set message_text = 'ticket_price_currency_mismatch';
                  end if;
                  if new.price_tier_id is not null and not exists (
                       select 1 from ticket_types t join price_tiers p on p.event_id = t.event_id
                       where t.id = new.ticket_type_id and p.id = new.price_tier_id) then
                    signal sqlstate '45000' set message_text = 'ticket_price_tier_other_event';
                  end if;
                end
                SQL);

            $this->trigger(<<<SQL
                create trigger ticket_type_pools_same_event_{$op} before {$op} on ticket_type_pools
                for each row
                begin
                  if not exists (select 1 from ticket_types t join capacity_pools p on p.event_id = t.event_id
                                 where t.id = new.ticket_type_id and p.id = new.pool_id) then
                    signal sqlstate '45000' set message_text = 'pool_other_event';
                  end if;
                end
                SQL);

            $this->trigger(<<<SQL
                create trigger discount_code_ticket_types_same_event_{$op} before {$op} on discount_code_ticket_types
                for each row
                begin
                  if exists (select 1 from discount_codes d join ticket_types t on t.id = new.ticket_type_id
                             where d.id = new.discount_code_id and d.event_id is not null and d.event_id <> t.event_id) then
                    signal sqlstate '45000' set message_text = 'discount_code_type_other_event';
                  end if;
                end
                SQL);
        }

        $this->trigger(<<<'SQL'
            create trigger orders_currency_matches_event before insert on orders
            for each row
            begin
              if not exists (select 1 from events e where e.id = new.event_id and e.currency = new.currency) then
                signal sqlstate '45000' set message_text = 'order_currency_mismatch';
              end if;
            end
            SQL);

        $this->trigger(<<<'SQL'
            create trigger events_currency_locked before update on events
            for each row
            begin
              if new.currency <> old.currency and (
                   exists (select 1 from orders o where o.event_id = old.id)
                   or exists (select 1 from ticket_prices p join ticket_types t on t.id = p.ticket_type_id where t.event_id = old.id)) then
                signal sqlstate '45000' set message_text = 'event_currency_locked';
              end if;
            end
            SQL);
    }

    public function down(): void
    {
        foreach (['ticket_prices_same_event', 'ticket_type_pools_same_event', 'discount_code_ticket_types_same_event'] as $name) {
            DB::unprepared("drop trigger if exists {$name}_insert");
            DB::unprepared("drop trigger if exists {$name}_update");
        }
        DB::unprepared('drop trigger if exists orders_currency_matches_event');
        DB::unprepared('drop trigger if exists events_currency_locked');

        foreach (self::NO_DEFAULT as $table => $columns) {
            foreach ($columns as $column) {
                $default = $column === 'timezone' ? 'Europe/Lisbon' : 'eur';
                DB::statement("alter table $table alter column $column set default '$default'");
            }
        }
    }
};
