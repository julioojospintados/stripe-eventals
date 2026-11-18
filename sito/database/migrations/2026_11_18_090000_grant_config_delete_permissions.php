<?php

use App\Enums\StaffRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Solo dati: sulle installazioni già seminate i permessi *.delete esistevano ma nessuno li aveva. Il seeder dà solo i
 * permessi nuovi, quindi qui admin e organizzatore ricevono i loro *.delete predefiniti. Su un database vuoto non fa nulla.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([StaffRole::Admin, StaffRole::Organizzatore] as $staffRole) {
            $role = Role::where('name', $staffRole->value)->where('guard_name', 'web')->first();
            $delete = array_filter($staffRole->permissions(), fn ($p) => str_ends_with($p, '.delete'));
            $role?->givePermissionTo(Permission::where('guard_name', 'web')->whereIn('name', $delete)->get());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
