<?php

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Crea (o aggiorna) un utente dello staff via SSH. La password si chiede a video e non passa mai dalla riga di
 * comando. Al primo accesso a /admin il pannello chiede di attivare la MFA.
 */
class CreateAdmin extends Command
{
    protected $signature = 'eas:create-admin
        {email : Email dell\'utente dello staff}
        {--name= : Nome mostrato nel pannello}
        {--role=admin : admin, commerciale o organizzatore}';

    protected $description = 'Crea un utente dello staff con il suo ruolo per il pannello /admin';

    public function handle(): int
    {
        $role = StaffRole::tryFrom($this->option('role'));
        if ($role === null) {
            $this->error('Ruolo non valido: usa '.implode(', ', StaffRole::names()).'.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (almeno 12 caratteri)');
        if ($password !== $this->secret('Ripeti la password')) {
            $this->error('Le due password non coincidono.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', Password::min(12)]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::firstOrNew(['email' => $email]);
        $user->name = $this->option('name') ?: ($user->name ?? strstr($email, '@', true));
        $user->password = $password;
        $user->save();
        // Role::findByName fallisce se i ruoli non sono stati creati: php artisan db:seed --class=RolesAndPermissionsSeeder
        $user->assignRole(Role::findByName($role->value));

        AuditLog::record('staff.created', $user, data: ['role' => $role->value]);
        $this->info("Utente $email pronto con il ruolo {$role->value}. Al primo accesso a /admin attiva la MFA.");

        return self::SUCCESS;
    }
}
