<?php

namespace App\Filament\Resources\TicketTypes;

use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Filament\Resources\TicketTypes\Pages\ManageTicketTypes;
use App\Models\Event;
use App\Models\TicketType;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Tipi di biglietto con i pool di posti che consumano. Si spengono, non si cancellano. */
class TicketTypeResource extends Resource
{
    protected static ?string $model = TicketType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::defaultId()),
            Select::make('kind')->options(TicketKind::class)->required()->live(),
            TextInput::make('name.en')->label('Name (EN)')->required()->maxLength(200),
            TextInput::make('name.it')->label('Name (IT)')->maxLength(200),
            Select::make('channel')->options(TicketChannel::class)->required()->default(TicketChannel::Public)
                ->helperText('Complimentary passes must use the panel channel.'),
            Select::make('event_day_id')->relationship('eventDay', 'day')->label('Day (day pass only)')
                ->visible(self::kindIs(TicketKind::DayPass))
                ->required(self::kindIs(TicketKind::DayPass)),
            Select::make('program_session_id')->relationship('programSession', 'code')->label('Session (workshop only)')
                ->visible(self::kindIs(TicketKind::PaidSession))
                ->required(self::kindIs(TicketKind::PaidSession)),
            TextInput::make('max_per_order')->integer()->minValue(1)->maxValue(50),
            TextInput::make('max_per_host')->integer()->minValue(1)->maxValue(5)->label('Max per pass (companion only)')
                ->visible(self::kindIs(TicketKind::Companion))
                ->required(self::kindIs(TicketKind::Companion)),
            DateTimePicker::make('sales_start')->timezone(fn (Get $get) => Event::timezoneOf($get('event_id'))),
            DateTimePicker::make('sales_end')->timezone(fn (Get $get) => Event::timezoneOf($get('event_id')))->after('sales_start'),
            TextInput::make('position')->integer()->default(0),
            Toggle::make('active')->default(true),
            CheckboxList::make('pools')->relationship('pools', 'name')->columns(3)->columnSpanFull()
                ->helperText('Seats used by one ticket of this type: one seat in each pool.'),
        ]);
    }

    /** Il campo kind vale l'enum (record caricato) o la sua stringa (scelta nel form). */
    private static function kindIs(TicketKind $kind): Closure
    {
        return fn (Get $get) => TicketKind::tryFrom($get('kind') instanceof TicketKind ? $get('kind')->value : (string) $get('kind')) === $kind;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('event.slug')->label('Event')->toggleable(),
                TextColumn::make('name.en')->label('Name')->searchable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('channel'),
                TextColumn::make('max_per_order'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('pools.name')->label('Pools')->listWithLineBreaks()->limitList(3),
            ])
            ->filters([SelectFilter::make('kind')->options(TicketKind::class)])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketTypes::route('/')];
    }
}
