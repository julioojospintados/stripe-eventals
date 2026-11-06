<?php

// Consensi alla newsletter e registro audit, entrambi solo in aggiunta. Autore: Giulio Occhipinti.
// Consensi: ogni consenso o revoca è una riga nuova; vale l'ultima per (email, scopo). Export CSV in Fase 1,
// sincronizzazione con Mailchimp in Fase 2.
// Registro audit: azioni dello staff, omaggi, correzioni del nominativo, codici sconto, fasce e prezzi, rimborsi,
// export, accessi al pannello. I trigger vietano UPDATE e DELETE (se l'hosting ammette i trigger).

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
        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('email', 254);
            $table->string('purpose', 30)->default('newsletter');
            $table->boolean('granted');
            // Dove è stato dato: checkout, account, signup_form, import.
            $table->string('source', 30);
            $table->string('locale', 5)->default('en');
            // Versione del testo del consenso mostrato.
            $table->string('text_version', 30);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('occurred_at')->useCurrent();

            $table->index(['email', 'purpose', 'occurred_at']);
        });

        DB::statement("alter table consents add constraint consents_purpose_valid check (purpose in ('newsletter'))");
        DB::statement("alter table consents add constraint consents_source_valid check (
            source in ('checkout', 'account', 'signup_form', 'import'))");

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 20);  // staff, user, system
            $table->string('action', 100);     // es. staff.created, discount_code.updated
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 36)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('data')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'occurred_at']);
        });

        DB::statement("alter table audit_log add constraint audit_log_actor_type_valid check (
            actor_type in ('staff', 'user', 'system'))");

        foreach (['consents', 'audit_log'] as $table) {
            foreach (['update', 'delete'] as $op) {
                $this->trigger(<<<SQL
                    create trigger {$table}_no_{$op} before {$op} on {$table}
                    for each row
                    begin
                      signal sqlstate '45000' set message_text = '{$table}_append_only';
                    end
                    SQL);
            }
        }
    }

    public function down(): void
    {
        foreach (['consents', 'audit_log'] as $table) {
            DB::unprepared("drop trigger if exists {$table}_no_update");
            DB::unprepared("drop trigger if exists {$table}_no_delete");
            Schema::dropIfExists($table);
        }
    }
};
