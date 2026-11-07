<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\ManageRooms;
use App\Models\Event;
use App\Models\Room;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Sale dell'evento; l'ordine (trascinamento o position) è quello delle colonne della griglia pubblica. */
class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Programme';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::value('id')),
            TextInput::make('code')->required()->regex('/^[A-Za-z0-9-]{1,30}$/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('event_id', $get('event_id')))
                ->helperText('Key used in sessions.csv, e.g. room-01. Do not change it after the first import.'),
            TextInput::make('name.en')->label('Name (EN)')->required()->maxLength(100),
            TextInput::make('name.it')->label('Name (IT)')->maxLength(100),
            TextInput::make('capacity')->label('Seats')->required()->integer()->minValue(1),
            TextInput::make('floor')->integer()->helperText('0 ground floor, 1 first, -1 basement.'),
            TextInput::make('position')->integer()->minValue(0)->default(0)->helperText('Column in the programme grid.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#'),
                TextColumn::make('name.en')->label('Name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('capacity')->label('Seats')->numeric(),
                TextColumn::make('floor'),
                TextColumn::make('program_sessions_count')->counts('programSessions')->label('Sessions'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRooms::route('/')];
    }
}
