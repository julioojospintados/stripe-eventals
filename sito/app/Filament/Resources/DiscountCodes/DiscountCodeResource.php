<?php

namespace App\Filament\Resources\DiscountCodes;

use App\Enums\DiscountType;
use App\Enums\PriceRole;
use App\Filament\Resources\DiscountCodes\Pages\ManageDiscountCodes;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\TicketType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Codici sconto (admin, commerciale, organizzatore). Lo sconto lo calcola il server (PriceResolver); creazioni e
 * modifiche vanno nel registro audit con l'autore. Si disattivano, non si cancellano.
 */
class DiscountCodeResource extends Resource
{
    protected static ?string $model = DiscountCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $isPercent = fn (Get $get) => in_array($get('discount_type'), [DiscountType::Percent, DiscountType::Percent->value], true);

        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true)
                ->regex('/^[A-Za-z0-9_-]{3,40}$/')->helperText('3–40 letters, digits, - or _. Saved in capitals.'),
            TextInput::make('description')->maxLength(500),
            Select::make('event_id')->relationship('event', 'slug')->default(fn () => Event::value('id')),
            Radio::make('discount_type')->options(DiscountType::class)->required()->live()->inline()
                // Il CHECK discount_codes_shape vuole solo il campo del tipo scelto.
                ->afterStateUpdated(function (Set $set) {
                    $set('percent_off', null);
                    $set('amount_off_cents', null);
                }),
            TextInput::make('percent_off')->integer()->minValue(1)->maxValue(100)->suffix('%')
                ->visible($isPercent)->required($isPercent)->dehydratedWhenHidden(),
            TextInput::make('amount_off_cents')->label('Amount off (€)')->numeric()->minValue(0.01)->step('0.01')
                ->visible(fn (Get $get) => ! $isPercent($get) && filled($get('discount_type')))
                ->required(fn (Get $get) => ! $isPercent($get) && filled($get('discount_type')))
                ->dehydratedWhenHidden()
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round($state * 100)),
            CheckboxList::make('eligible_roles')->options(PriceRole::class)->helperText('None = everyone.')
                ->dehydrateStateUsing(fn ($state) => $state ?: null),
            CheckboxList::make('ticketTypes')->relationship('ticketTypes', 'position')
                ->getOptionLabelFromRecordUsing(fn (TicketType $type) => $type->name['en'])
                ->helperText('None = every ticket type.'),
            Toggle::make('combinable_with_role_price')->label('Also on top of the member price'),
            Toggle::make('applies_to_membership'),
            TextInput::make('max_redemptions')->integer()->minValue(1)->helperText('Empty = unlimited.'),
            TextInput::make('per_user_limit')->integer()->minValue(1),
            DateTimePicker::make('valid_from')->timezone('Europe/Lisbon'),
            DateTimePicker::make('valid_to')->timezone('Europe/Lisbon')->after('valid_from'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->searchable()->copyable(),
                TextColumn::make('discount_type')->badge(),
                TextColumn::make('percent_off')->suffix('%')->placeholder('—'),
                TextColumn::make('amount_off_cents')->label('Amount off')->money('EUR', divideBy: 100)->placeholder('—'),
                TextColumn::make('redeemed_count')->label('Used')
                    ->formatStateUsing(fn (DiscountCode $record) => $record->redeemed_count.($record->max_redemptions ? ' / '.$record->max_redemptions : '')),
                TextColumn::make('valid_to')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->placeholder('—'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('creator.name')->label('Created by'),
            ])
            ->recordActions([
                EditAction::make()->after(fn (DiscountCode $record) => self::audit('discount_code.updated', $record)),
            ]);
    }

    public static function audit(string $action, DiscountCode $record): void
    {
        AuditLog::record($action, $record, auth()->user(), ['code' => $record->code, 'changes' => $record->getChanges()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDiscountCodes::route('/')];
    }
}
