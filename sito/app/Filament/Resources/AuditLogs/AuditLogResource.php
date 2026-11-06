<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Registro audit in sola lettura (il database lo tiene solo in aggiunta). */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('occurred_at')->dateTime('d/m/Y H:i:s', 'Europe/Lisbon'),
            TextEntry::make('action'),
            TextEntry::make('actor.email')->label('By')->placeholder('system'),
            TextEntry::make('entity_type')->placeholder('—'),
            TextEntry::make('entity_id')->placeholder('—'),
            TextEntry::make('ip')->placeholder('—'),
            TextEntry::make('data')->columnSpanFull()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon')->sortable(),
                TextColumn::make('action')->searchable(),
                TextColumn::make('actor.email')->label('By')->placeholder('system'),
                TextColumn::make('entity_type'),
                TextColumn::make('entity_id')->limit(12),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditLogs::route('/')];
    }
}
