<?php

// Membership annuale con Stripe Billing (docs/architettura.md §6.5). Autore: Giulio Occhipinti.
// Membro non è un ruolo salvato: si deriva da memberships. Attiva subito a checkout.session.completed pagato
// (webhook o pagina di successo, idempotente), poi aggiornata solo dagli eventi Stripe.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('name');
            // Prezzo creato in Stripe (modalità test finché il cliente non dice altro); vuoto finché non esiste.
            $table->string('stripe_price_id')->nullable()->unique();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('eur');
            $table->string('billing_interval', 10)->default('year');
            $table->boolean('active')->default(true);
            $table->datetimes();
        });

        DB::statement("alter table membership_plans add constraint membership_plans_interval_valid check (
            billing_interval in ('month', 'year'))");
        DB::statement('alter table membership_plans add constraint membership_plans_currency_lower check (currency = lower(currency))');

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id');
            // Stati della subscription di Stripe.
            $table->string('status', 30);
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            // Gli eventi Stripe arrivano in disordine: si applica solo un evento più recente di questo.
            $table->dateTime('last_stripe_event_at')->nullable();
            $table->datetimes();

            $table->index(['user_id', 'status', 'current_period_end']);
        });

        DB::statement("alter table memberships add constraint memberships_status_valid check (status in (
            'incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
    }
};
