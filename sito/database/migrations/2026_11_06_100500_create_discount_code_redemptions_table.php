<?php

// Utilizzi dei codici sconto: uno per ordine, contato alla riserva e liberato se l'ordine scade (released_at).
// Autore: Giulio Occhipinti. Il limite per persona (discount_codes.per_user_limit) lo controlla il Service contando
// gli utilizzi non liberati, dentro la stessa transazione che incrementa redeemed_count.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_code_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('discount_code_id')->constrained('discount_codes')->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 200)->nullable();

            $table->index(['discount_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_code_redemptions');
    }
};
