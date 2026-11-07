<?php

namespace App\Filament\Resources\MembershipPlans;

use App\Filament\Resources\MembershipPlans\Pages\ManageMembershipPlans;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Prezzo della membership: importo mostrato sul sito e Price ID Stripe usato dal Checkout (senza Price ID vale
 * STRIPE_MEMBERSHIP_PRICE del .env). Il Price si crea nella Dashboard Stripe: l'importo qui deve essere lo stesso.
 */
class MembershipPlanResource extends Resource
{
    protected static ?string $model = MembershipPlan::class;

    protected static ?string $navigationLabel = 'Membership price';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('amount_cents')->label('Amount per year (€)')->required()->numeric()->minValue(0)->step('0.01')
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn ($state) => (int) round($state * 100)),
            TextInput::make('stripe_price_id')->label('Stripe Price ID')->maxLength(255)->regex('/^price_[A-Za-z0-9]+$/')
                ->unique(ignoreRecord: true)
                ->helperText('Create the yearly Price in the Stripe Dashboard with the same amount, then paste its id (price_…).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name.en')->label('Plan'),
                TextColumn::make('amount_cents')->label('Amount')->money('EUR', divideBy: 100),
                TextColumn::make('stripe_price_id')->label('Stripe Price ID')->placeholder('from .env')->copyable(),
                TextColumn::make('active')->badge(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (MembershipPlan $record) => AuditLog::record('membership_plan.updated', $record, auth()->user(),
                    $record->only('amount_cents', 'stripe_price_id'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembershipPlans::route('/')];
    }
}
