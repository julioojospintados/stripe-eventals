<?php

namespace App\Filament\Resources\Roles;

use App\Enums\StaffRole;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

/**
 * Ruoli e permessi (RolePolicy): si vedono con roles.view; i permessi di commerciale e organizzatore si cambiano con
 * roles.update, con audit. Admin ha sempre tutto. I valori predefiniti sono in App\Enums\StaffRole.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = 'Roles and permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            CheckboxList::make('permissions')->relationship('permissions', 'name')->columns(2)->bulkToggleable()
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name.' · '.(StaffRole::allPermissions()[$record->name] ?? '')),
        ]);
    }

    public static function table(Table $table): Table
    {
        $old = [];

        return $table
            ->columns([
                TextColumn::make('name')->label('Role'),
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
                TextColumn::make('permissions.name')->badge()->limitList(6)->expandableLimitedList(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Edit permissions')
                    ->before(function (Role $record) use (&$old) {
                        $old = $record->permissions()->pluck('name')->all();
                    })
                    ->after(function (Role $record) use (&$old) {
                        app(PermissionRegistrar::class)->forgetCachedPermissions();
                        $new = $record->permissions()->pluck('name')->all();
                        AuditLog::record('role.permissions_updated', $record, auth()->user(), [
                            'role' => $record->name, 'added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new)),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
