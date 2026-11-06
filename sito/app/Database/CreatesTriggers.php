<?php

namespace App\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trigger di difesa nelle migrazioni (MySQL 8 / MariaDB).
 *
 * Con il log binario attivo un utente senza SUPER riceve l'errore 1419: l'hosting deve impostare
 * log_bin_trust_function_creators = 1. Se non può, DB_TRIGGERS_OPTIONAL=true fa saltare il trigger con un avviso nel
 * log; la regola resta nel Service, nelle enum e nei test (docs/architettura.md §3).
 */
trait CreatesTriggers
{
    protected function trigger(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1419 || ! config('database.triggers_optional')) {
                throw $e;
            }

            Log::warning('Trigger di difesa non creato (errore 1419): '.strtok(trim($sql), "\n"));
        }
    }
}
