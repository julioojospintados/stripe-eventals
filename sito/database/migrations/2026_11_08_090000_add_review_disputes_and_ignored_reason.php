<?php

// Chiusure del backend. Autore: Giulio Occhipinti.
//   - orders: stato needs_review (importo Stripe diverso dal totale: niente biglietti finché lo staff non decide) e
//     review_reason (perché l'ordine va rivisto: amount_mismatch, refund_failed, dispute_lost).
//   - stripe_events.ignored_reason: perché un evento è stato ignorato (tipo non gestito, ordine sconosciuto…).
//   - disputes: contestazioni dei pagamenti (charge.dispute.*).
// DROP CONSTRAINT: MariaDB 10.2+ e MySQL 8.0.19+.

use App\Database\CreatesTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use CreatesTriggers;

    /** Transizioni ammesse "da>a" (quelle della migrazione 100000 più needs_review). Allineata con App\Enums\OrderStatus. */
    private const TRANSITIONS = [
        'pending>paid', 'pending>processing', 'pending>expired', 'pending>cancelled', 'pending>failed',
        'pending>needs_refund',
        'processing>paid', 'processing>failed', 'processing>needs_refund',
        'expired>paid', 'failed>paid', 'cancelled>paid',
        'expired>needs_refund', 'failed>needs_refund', 'cancelled>needs_refund',
        'paid>partially_refunded', 'paid>refunded', 'partially_refunded>refunded',
        'needs_refund>refunded',
        'paid>cancelled',
        // Importo diverso dal totale (anche con pagamento tardivo); poi lo staff emette i biglietti o rimborsa.
        'pending>needs_review', 'processing>needs_review',
        'expired>needs_review', 'failed>needs_review', 'cancelled>needs_review',
        'needs_review>paid', 'needs_review>needs_refund', 'needs_review>refunded',
    ];

    private const STATUSES = [
        'pending', 'processing', 'paid', 'expired', 'failed', 'needs_refund', 'cancelled',
        'partially_refunded', 'refunded', 'needs_review',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('review_reason', 30)->nullable()->after('status');
        });
        $this->guard(self::STATUSES, self::TRANSITIONS);

        Schema::table('stripe_events', function (Blueprint $table) {
            $table->string('ignored_reason', 100)->nullable()->after('last_error');
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stripe_dispute_id')->unique();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('stripe_charge_id')->nullable();
            $table->integer('amount_cents');
            $table->char('currency', 3);
            $table->string('reason', 50)->nullable();
            // Stato di Stripe: warning_needs_response, needs_response, under_review, won, lost, prevented…
            $table->string('status', 30);
            $table->dateTime('evidence_due_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::table('stripe_events', fn (Blueprint $table) => $table->dropColumn('ignored_reason'));
        $this->guard(array_slice(self::STATUSES, 0, -1), array_values(array_filter(self::TRANSITIONS, fn ($t) => ! str_contains($t, 'needs_review'))));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('review_reason'));
    }

    /** Ricrea il CHECK degli stati e il trigger orders_guard (corpo uguale alla migrazione 100000). */
    private function guard(array $statuses, array $transitions): void
    {
        DB::statement('alter table orders drop constraint orders_status_valid');
        DB::statement("alter table orders add constraint orders_status_valid check (status in ('".implode("','", $statuses)."'))");

        $allowed = "'".implode("','", $transitions)."'";
        DB::unprepared('drop trigger if exists orders_guard');
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
    }
};
