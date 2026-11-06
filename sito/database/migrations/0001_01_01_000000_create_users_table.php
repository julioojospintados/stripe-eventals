<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Salvata in minuscolo dal model.
            $table->string('email', 254)->unique();
            $table->dateTime('email_verified_at')->nullable();
            // Solo per lo staff (argon2id); chi compra entra con un codice via email e non ha password.
            $table->string('password')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->dateTime('marketing_consent_at')->nullable();
            // MFA con app TOTP di Filament (obbligatoria per lo staff), salvate cifrate dal model.
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            // Chi chiede la cancellazione viene anonimizzato: ordini e biglietti restano.
            $table->dateTime('anonymized_at')->nullable();
            $table->rememberToken();
            $table->datetimes();
        });

        DB::statement("alter table users add constraint users_locale_valid check (locale in ('en', 'it'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
