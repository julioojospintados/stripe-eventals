<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Tutti i test girano su MariaDB/MySQL (database eas8_test, vedi phpunit.xml): migrate:fresh una volta, poi ogni
| test in una transazione annullata alla fine.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** Salta il test se il trigger non c'è (hosting con DB_TRIGGERS_OPTIONAL=true): la regola resta da provare nel Service. */
function requireTrigger(string $name): void
{
    $exists = DB::table('information_schema.triggers')
        ->where('trigger_schema', DB::getDatabaseName())
        ->where('trigger_name', $name)
        ->exists();

    if (! $exists) {
        test()->markTestSkipped("Trigger $name assente (DB_TRIGGERS_OPTIONAL=true).");
    }
}
