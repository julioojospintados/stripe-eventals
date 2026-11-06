<?php

namespace App\Filament\Resources\Events;

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Pages\ManageEvents;
use App\Models\Event;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Evento, date e giorni (organizzatore e admin; il commerciale legge). */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Event';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')->columns(2)->columnSpanFull()->schema([
                TextInput::make('title.en')->label('Title (EN)')->required()->maxLength(200),
                TextInput::make('title.it')->label('Title (IT)')->maxLength(200),
                Textarea::make('summary.en')->label('Summary (EN)')->rows(2),
                Textarea::make('summary.it')->label('Summary (IT)')->rows(2),
                TextInput::make('slug')->required()->maxLength(100)->unique(ignoreRecord: true)->alphaDash(),
                Select::make('status')->options(EventStatus::class)->required(),
                TextInput::make('venue_name')->maxLength(200),
                TextInput::make('venue_address')->maxLength(500),
            ]),
            Section::make('Dates')->columns(2)->columnSpanFull()->schema([
                DatePicker::make('starts_on')->required(),
                DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
                DateTimePicker::make('sales_open_at')->timezone('Europe/Lisbon')->helperText('Lisbon time. Empty = open now.'),
                DateTimePicker::make('sales_close_at')->timezone('Europe/Lisbon')->after('sales_open_at'),
                TextInput::make('timezone')->required()->default('Europe/Lisbon')->in(timezone_identifiers_list()),
            ]),
            Repeater::make('days')->relationship()->columnSpanFull()->columns(3)
                ->schema([
                    DatePicker::make('day')->required(),
                    TextInput::make('label.en')->label('Label (EN)'),
                    TextInput::make('label.it')->label('Label (IT)'),
                ])
                ->deletable(false)->reorderable(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title.en')->label('Title'),
                TextColumn::make('status')->badge(),
                TextColumn::make('starts_on')->date(),
                TextColumn::make('ends_on')->date(),
                TextColumn::make('venue_name'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEvents::route('/')];
    }
}
