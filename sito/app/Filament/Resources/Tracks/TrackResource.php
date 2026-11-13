<?php

namespace App\Filament\Resources\Tracks;

use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Models\Event;
use App\Models\Track;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Tracce tematiche; il nome inglese è anche la chiave della colonna track di sessions.csv. */
class TrackResource extends Resource
{
    protected static ?string $model = Track::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Programme';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::defaultId()),
            TextInput::make('name.en')->label('Name (EN)')->required()->maxLength(100)
                ->helperText('Same text as the track column of sessions.csv.')
                ->rules([fn (Get $get, ?Track $record) => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                    $taken = Track::where('event_id', $get('event_id'))->where('slug', Track::slugFor((string) $value))
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                    if ($taken) {
                        $fail('A track with this name already exists.');
                    }
                }]),
            TextInput::make('name.it')->label('Name (IT)')->maxLength(100),
            ColorPicker::make('color')->regex('/^#[0-9A-Fa-f]{6}$/'),
            TextInput::make('position')->integer()->minValue(0)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('event.slug')->label('Event')->toggleable(),
                ColorColumn::make('color'),
                TextColumn::make('name.en')->label('Name')->searchable(),
                TextColumn::make('name.it')->label('Name (IT)'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTracks::route('/')];
    }
}
