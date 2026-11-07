<?php

namespace App\Filament\Resources\PriceTiers;

use App\Filament\Resources\PriceTiers\Pages\ManagePriceTiers;
use App\Models\Event;
use App\Models\PriceTier;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
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
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Fasce di prezzo con date e attivazione. Due fasce attive dello stesso evento non si sovrappongono: il form lo dice
 * con un errore leggibile; il trigger price_tiers_no_overlap resta l'ultima difesa.
 */
class PriceTierResource extends Resource
{
    protected static ?string $model = PriceTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::value('id')),
            TextInput::make('code')->required()->regex('/^[a-z_]{2,30}$/')->helperText('Lowercase letters and _, e.g. early_bird.'),
            TextInput::make('name.en')->label('Name (EN)')->required()->maxLength(100),
            TextInput::make('name.it')->label('Name (IT)')->maxLength(100),
            DateTimePicker::make('starts_at')->timezone('Europe/Lisbon')->required()->helperText('Lisbon time, included.'),
            DateTimePicker::make('ends_at')->timezone('Europe/Lisbon')->required()->after('starts_at')
                ->helperText('Lisbon time, excluded.')
                ->rules([fn (Get $get, ?PriceTier $record) => self::noOverlap($get, $record)]),
            TextInput::make('position')->integer()->default(0),
            Toggle::make('active')->default(true),
        ]);
    }

    /** Errore leggibile se la fascia attiva si sovrappone a un'altra attiva dello stesso evento. */
    public static function noOverlap(Get $get, ?PriceTier $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($get, $record) {
            if (! $get('active') || ! $get('starts_at') || ! $value) {
                return;
            }
            $tz = 'Europe/Lisbon';
            // $value è l'ora di Lisbona scritta nel campo; $get dà lo stato già convertito in UTC dal DateTimePicker.
            $other = PriceTier::where('event_id', $get('event_id'))->where('active', true)
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->where('starts_at', '<', Carbon::parse($value, $tz)->utc())
                ->where('ends_at', '>', Carbon::parse($get('starts_at'), 'UTC'))
                ->first();
            if ($other) {
                $fail(__('This tier overlaps the active tier ":name" (:from – :to). Change the dates or turn one of them off.', [
                    'name' => $other->name['en'] ?? $other->code,
                    'from' => $other->starts_at->timezone($tz)->format('d/m/Y H:i'),
                    'to' => $other->ends_at->timezone($tz)->format('d/m/Y H:i'),
                ]));
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('name.en')->label('Name'),
                TextColumn::make('code'),
                TextColumn::make('starts_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon'),
                TextColumn::make('ends_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon'),
                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePriceTiers::route('/')];
    }
}
