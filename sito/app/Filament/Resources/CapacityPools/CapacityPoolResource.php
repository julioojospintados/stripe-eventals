<?php

namespace App\Filament\Resources\CapacityPools;

use App\Filament\Resources\CapacityPools\Pages\ManageCapacityPools;
use App\Models\CapacityPool;
use App\Models\Event;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Capienze. held e confirmed li cambia solo il CapacityService; qui si leggono e si cambia la capienza. */
class CapacityPoolResource extends Resource
{
    protected static ?string $model = CapacityPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::value('id')),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('capacity')->required()->integer()
                // Sotto i posti già venduti o in riserva il database rifiuta: qui lo si dice prima.
                ->minValue(fn (?CapacityPool $record) => $record ? $record->held + $record->confirmed : 0)
                ->validationMessages(['min' => 'Capacity cannot be lower than the seats already sold or held (:min).']),
            TextInput::make('max_per_order')->integer()->minValue(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('capacity')->numeric(),
                TextColumn::make('confirmed')->label('Sold')->numeric(),
                TextColumn::make('held')->label('In checkout')->numeric(),
                TextColumn::make('left')->label('Left')->numeric()
                    ->state(fn (CapacityPool $record) => $record->capacity - $record->held - $record->confirmed),
                TextColumn::make('max_per_order'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCapacityPools::route('/')];
    }
}
