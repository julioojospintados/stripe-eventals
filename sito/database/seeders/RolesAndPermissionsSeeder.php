<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ruoli e permessi da App\Enums\StaffRole. Si può rilanciare in produzione:
 *   - crea i permessi mancanti e cancella quelli che non esistono più nel codice;
 *   - un ruolo nuovo riceve i permessi predefiniti di StaffRole;
 *   - un ruolo esistente riceve solo i permessi nuovi come da StaffRole: le scelte fatte nel pannello (Roles) restano;
 *   - admin tiene sempre StaffRole::ADMIN_LOCKED.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = array_keys(StaffRole::allPermissions());
        $existing = Permission::where('guard_name', 'web')->pluck('name')->all();
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Permission::whereNotIn('name', $names)->get()->each->delete();
        $new = array_diff($names, $existing);

        foreach (StaffRole::cases() as $staffRole) {
            $role = Role::where('name', $staffRole->value)->where('guard_name', 'web')->first();
            if (! $role) {
                Role::findOrCreate($staffRole->value, 'web')->syncPermissions($staffRole->permissions());

                continue;
            }
            $role->givePermissionTo(array_values(array_intersect($staffRole->permissions(), $new)));
            if ($staffRole === StaffRole::Admin) {
                $role->givePermissionTo(StaffRole::ADMIN_LOCKED);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
