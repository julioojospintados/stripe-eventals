<?php

namespace App\Filament\Resources\Memberships;

use App\Enums\MembershipStatus;
use App\Filament\Resources\Memberships\Pages\ManageMemberships;
use App\Models\Membership;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Membership in sola lettura: le aggiornano solo gli eventi Stripe; le modifiche si fanno nella Dashboard Stripe. */
class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('current_period_end', 'desc')
            ->columns([
                TextColumn::make('user.email')->label('Member')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_period_end')->label('Paid until')->dateTime('d/m/Y', 'Europe/Lisbon')->sortable(),
                IconColumn::make('cancel_at_period_end')->label('Cancels at end')->boolean(),
                TextColumn::make('activated_at')->dateTime('d/m/Y', 'Europe/Lisbon'),
                TextColumn::make('stripe_subscription_id')->label('Stripe subscription')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('status')->options(MembershipStatus::class)]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemberships::route('/')];
    }
}
