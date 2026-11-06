<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Ruoli e permessi da App\Enums\StaffRole. Si può rilanciare: allinea i permessi di ogni ruolo. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(StaffRole::PERMISSIONS) as $name) {
            Permission::findOrCreate($name);
        }

        foreach (StaffRole::cases() as $role) {
            Role::findOrCreate($role->value)->syncPermissions($role->permissions());
        }
    }
}
