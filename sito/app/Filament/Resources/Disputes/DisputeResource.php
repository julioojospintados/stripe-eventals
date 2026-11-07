<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ManageDisputes;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Contestazioni in sola lettura (charge.dispute.*): la risposta con le prove si dà dalla Dashboard Stripe. */
class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Opened')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->sortable(),
                TextColumn::make('order.buyer_email')->label('Buyer')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'won', 'warning_closed', 'prevented' => 'success',
                    'lost' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('reason'),
                TextColumn::make('amount_cents')->label('Amount')->money('EUR', divideBy: 100),
                TextColumn::make('evidence_due_by')->label('Answer by')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->placeholder('—'),
                TextColumn::make('closed_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->placeholder('—'),
                TextColumn::make('stripe_dispute_id')->label('Stripe')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDisputes::route('/')];
    }
}
