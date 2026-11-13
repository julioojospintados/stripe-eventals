<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Sviluppo: ruoli dello staff, l'EAS8 come evento pubblicato e un evento dimostrativo in bozza in un altro fuso e
     * un'altra valuta. In produzione i seeder si lanciano uno per uno (docs/tecnico/esercizio.md). Nessun utente: il
     * primo admin si crea con php artisan eventals:create-admin.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            Eas8Seeder::class,
            DemoEventSeeder::class,
        ]);
    }
}
