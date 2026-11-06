<?php

// Tabelle minime presupposte dalle migrazioni bozza, solo per le prove di db/prove/prove_mysql.php.
// Autore: Giulio Occhipinti. 06/11/2026. Non è il modello vero di events, event_days, program_sessions, discount_codes.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('events', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('slug')->unique(); $t->datetimes(); });
        Schema::create('event_days', function (Blueprint $t) { $t->uuid('id')->primary(); $t->foreignUuid('event_id')->constrained('events'); $t->date('day'); });
        Schema::create('program_sessions', function (Blueprint $t) { $t->uuid('id')->primary(); });
        Schema::create('discount_codes', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('code')->unique(); });
    }
};
