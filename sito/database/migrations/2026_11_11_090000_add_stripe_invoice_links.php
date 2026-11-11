<?php

// Fatture di Stripe. Autore: Giulio Occhipinti.
//   - orders: fattura creata dal Checkout dei biglietti (invoice_creation), id e pagina pubblica di Stripe
//     (hosted_invoice_url), dal checkout.session.completed e dall'invoice.paid.
//   - memberships: pagina dell'ultima fattura pagata della subscription (invoice.paid).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable();
            $table->string('stripe_invoice_url', 1000)->nullable();
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('latest_invoice_url', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['stripe_invoice_id', 'stripe_invoice_url']));
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn('latest_invoice_url'));
    }
};
