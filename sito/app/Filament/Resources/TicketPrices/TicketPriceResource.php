<?php

namespace App\Filament\Resources\TicketPrices;

use App\Enums\PriceRole;
use App\Filament\Resources\TicketPrices\Pages\ManageTicketPrices;
use App\Models\TicketPrice;
use App\Models\TicketType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Prezzi per tipo × fascia × ruolo; senza fascia è il prezzo base. Importi in euro nel form, centesimi nel database. */
class TicketPriceResource extends Resource
{
    protected static ?string $model = TicketPrice::class;

    protected static ?string $navigationLabel = 'Prices';

    protected static ?string $modelLabel = 'price';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('ticket_type_id')->relationship('ticketType', 'position')->label('Ticket type')->required()->live()
                ->getOptionLabelFromRecordUsing(fn (TicketType $type) => $type->event->slug.' · '.$type->name['en']),
            // Solo le fasce dell'evento del tipo (lo vuole anche il trigger ticket_prices_same_event).
            Select::make('price_tier_id')->label('Tier')
                ->relationship('priceTier', 'code', fn (Builder $query, Get $get) => $query->where('event_id', TicketType::whereKey($get('ticket_type_id'))->value('event_id')))->placeholder('Base price (every tier)'),
            Select::make('price_role')->options(PriceRole::class)->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('ticket_type_id', $get('ticket_type_id'))
                    ->where('tier_key', $get('price_tier_id') ?: '*'))
                ->validationMessages(['unique' => 'There is already a price for this type, tier and role.']),
            TextInput::make('amount_cents')->label('Amount (event currency)')->required()->numeric()->minValue(0)->step('0.01')
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn ($state) => (int) round($state * 100)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticketType.event.slug')->label('Event')->toggleable(),
                TextColumn::make('ticketType.name.en')->label('Ticket type')->sortable(),
                TextColumn::make('priceTier.name.en')->label('Tier')->placeholder('Base'),
                TextColumn::make('price_role')->badge(),
                TextColumn::make('amount_cents')->label('Amount')->money(fn ($record) => $record->currency, divideBy: 100),
            ])
            ->filters([SelectFilter::make('ticket_type_id')->relationship('ticketType', 'position')->label('Ticket type')
                ->getOptionLabelFromRecordUsing(fn (TicketType $type) => $type->event->slug.' · '.$type->name['en'])])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketPrices::route('/')];
    }
}
