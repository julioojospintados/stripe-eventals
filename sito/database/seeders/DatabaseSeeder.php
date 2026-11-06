<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Ruoli dello staff e dati dell'EAS8. Nessun utente: il primo admin si crea con php artisan eas:create-admin. */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            Eas8Seeder::class,
        ]);
    }
}
