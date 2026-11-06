<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Utenti dello staff (solo admin): password argon2id di almeno 12 caratteri, MFA chiesta dal pannello al primo accesso. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Staff users';

    protected static ?string $modelLabel = 'staff user';

    protected static ?string $slug = 'staff-users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    /** Solo lo staff: i clienti non si gestiscono da qui. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('roles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(254)->unique(ignoreRecord: true),
            TextInput::make('password')->password()->revealable()->minLength(12)->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText('At least 12 characters. Leave empty to keep the current one.'),
            Select::make('roles')->relationship('roles', 'name')->multiple()->required()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->badge(),
                IconColumn::make('app_authentication_secret')->label('MFA')->boolean()
                    ->state(fn (User $record) => filled($record->app_authentication_secret)),
            ])
            ->recordActions([
                EditAction::make()->after(fn (User $record) => self::audit('staff.updated', $record)),
            ]);
    }

    public static function audit(string $action, User $record): void
    {
        AuditLog::record($action, $record, auth()->user(), ['email' => $record->email, 'roles' => $record->roles()->pluck('name')->all()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
