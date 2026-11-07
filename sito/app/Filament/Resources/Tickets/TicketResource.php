<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ManageTickets;
use App\Models\Ticket;
use App\Services\ShopException;
use App\Services\TicketService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Biglietti in sola lettura; «Correct name» (refuso, non cambio di intestatario, al massimo 3 volte) e «Resend ticket». */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('short_code')->label('Code')->searchable()->copyable(),
                TextColumn::make('attendee_name')->label('Name')->searchable(),
                TextColumn::make('attendee_email')->label('Email')->searchable(),
                TextColumn::make('ticketType.name.en')->label('Type'),
                TextColumn::make('status')->badge(),
                TextColumn::make('qr_version')->label('QR v.'),
                TextColumn::make('attendee_corrections_count')->label('Corrections'),
                TextColumn::make('issued_at')->dateTime('d/m/Y H:i', 'Europe/Lisbon'),
            ])
            ->filters([SelectFilter::make('status')->options(TicketStatus::class)])
            ->recordActions([self::correctAction(), self::resendAction()]);
    }

    /** Rimanda l'email con il QR (con il limite di invio), all'intestatario o a un altro indirizzo; va nel registro audit. */
    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Resend ticket')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('resend')
            ->visible(fn (Ticket $record) => $record->status === TicketStatus::Valid)
            ->schema([TextInput::make('to')->label('Send to')->email()->maxLength(254)->placeholder('Attendee email')])
            ->action(function (Ticket $record, array $data) {
                try {
                    $to = app(TicketService::class)->resend($record, $data['to'] ?? null, auth()->user());
                    Notification::make()->success()->title("Ticket queued for $to")->send();
                } catch (ShopException $e) {
                    Notification::make()->danger()->title(__('shop.'.$e->getMessage()))->send();
                }
            });
    }

    public static function correctAction(): Action
    {
        return Action::make('correctAttendee')
            ->label('Correct name')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('correctAttendee')
            ->visible(fn (Ticket $record) => $record->attendee_corrections_count < TicketService::MAX_CORRECTIONS)
            ->modalDescription('Only to fix a typo: a ticket is never passed to another person. If the email changes, the QR code changes and the ticket is sent to the new address.')
            ->fillForm(fn (Ticket $record) => ['name' => $record->attendee_name, 'email' => $record->attendee_email])
            ->schema([
                TextInput::make('name')->required()->maxLength(200),
                TextInput::make('email')->email()->required()->maxLength(254),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (Ticket $record, array $data, Action $action) {
                $old = $record->attendee_name;
                try {
                    app(TicketService::class)->correctAttendee($record, auth()->user(), $data['name'], $data['email'], $data['reason']);
                } catch (ShopException $e) {
                    Notification::make()->danger()->title(__('shop.'.$e->getMessage()))->send();
                    $action->halt();
                }
                similar_text(mb_strtolower($old), mb_strtolower($data['name']), $percent);
                Notification::make()->success()->title('Name corrected')
                    ->body($percent < 60 ? 'Warning: the new name is very different from the old one. A correction is for typos, not for a new attendee.' : null)
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageTickets::route('/')];
    }
}
